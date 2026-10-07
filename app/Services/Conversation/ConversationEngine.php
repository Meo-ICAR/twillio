<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConversationEngine
{
    private const PRIVACY_WARNING = '⚠️ Non inserire dati identificativi del cliente (nome, codice fiscale, telefono, email, P.IVA). In questa fase servono solo dati di profilo.';

    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** @var string[] file salvati in questa richiesta, da eliminare se l'invio fallisce */
    private array $storedPaths = [];

    public function __construct(
        private SensitiveDataGuard $guard,
        private WhatsAppClient $client,
    ) {}

    /** Elimina i file salvati da questa richiesta (da chiamare se la transazione è annullata). */
    public function discardStoredFiles(): void
    {
        Storage::disk('local')->delete($this->storedPaths);
        $this->storedPaths = [];
    }

    /** @return Reply[] */
    public function handle(IncomingMessage $m): array
    {
        $conv = Conversation::with('loanRequest')
            ->where('wa_number', $m->from)->where('status', 'attiva')->latest('id')->first();
        $command = $m->type === 'text' ? $this->normalize($m->text) : null;

        if (in_array($command, ['annulla', 'menu'], true)) {
            $conv && $this->close($conv, 'annullata');

            return $command === 'annulla' ? [Reply::text('Operazione annullata.'), $this->menu()] : [$this->menu()];
        }
        if (! $conv) {
            return $this->fromMenu($m);
        }
        if (! config("finanziamento.flows.{$conv->flow}.nodes.{$conv->node}")) {
            $this->close($conv, 'annullata');

            return [Reply::text('La conversazione non è più valida: ricominciamo dal menu.'), $this->menu()];
        }
        if ($this->isStale($conv)) {
            return $this->resume($conv, $m);
        }
        if ($command === 'indietro') {
            return $this->back($conv);
        }

        return $this->answer($conv, $m);
    }

    private function menu(): Reply
    {
        return Reply::choice(config('finanziamento.menu.body'), config('finanziamento.menu.options'));
    }

    private function fromMenu(IncomingMessage $m): array
    {
        $choice = $m->replyId ?? match ($this->normalize((string) $m->text)) {
            '1', 'richiedi', 'richiedi finanziamento' => 'menu_richiedi',
            '2', 'perfeziona', 'perfeziona finanziamento' => 'menu_perfeziona',
            '3', 'stato', 'stato pratiche' => 'menu_stato',
            default => null,
        };

        return match ($choice) {
            'menu_richiedi' => $this->start($m->from, 'richiesta'),
            'menu_perfeziona' => $this->start($m->from, 'perfezionamento'),
            'menu_stato' => $this->stato($m->from),
            default => [$this->menu()],
        };
    }

    private function start(string $from, string $flow): array
    {
        $conv = Conversation::create([
            'wa_number' => $from, 'flow' => $flow, 'data' => [], 'history' => [],
            'node' => config("finanziamento.flows.{$flow}.start"),
        ]);

        return $this->prompt($conv);
    }

    private function answer(Conversation $conv, IncomingMessage $m): array
    {
        $def = $this->def($conv->flow, $conv->node);

        if ($m->type === 'text' && $conv->flow === 'richiesta' && $this->guard->containsIdentifyingData($m->text)) {
            return [Reply::text(self::PRIVACY_WARNING), ...$this->prompt($conv)];
        }
        if ($m->type === 'unsupported') {
            return [Reply::text('Questo tipo di messaggio non è supportato: rispondi con un testo, una scelta o un file.'), ...$this->prompt($conv)];
        }

        $result = $this->read($conv, $def, $m);
        [$value, $error] = $result;
        $extra = $result[2] ?? [];
        if ($value === null) {
            return [Reply::text($error), ...$this->prompt($conv)];
        }
        if ($def['type'] === 'summary') {
            return $this->finish($conv, $value);
        }

        $data = $conv->data ?? [];
        foreach ($def['derives'] ?? [] as $key) {
            unset($data[$key]);
        }
        if ($def['save'] ?? true) {
            $data[$conv->node] = $value;
        }
        $data = array_merge($data, $extra);
        $history = $conv->history ?? [];
        $history[] = $conv->node;

        $next = $this->nextNode($def, $conv, $data, $value);
        $conv->update(['data' => $data, 'history' => $history, 'node' => $next]);

        return $this->prompt($conv);
    }

    /** @return array{0: ?string, 1: ?string, 2?: array<string,string>} [valore, errore, dati ricavati] */
    private function read(Conversation $conv, array $def, IncomingMessage $m): array
    {
        return match ($def['type']) {
            'choice', 'summary' => $this->readChoice($def, $m),
            'text' => $this->readText($def, $m),
            'code' => $this->readCode($conv, $m),
            'file' => $this->readFile($conv, $def, $m),
        };
    }

    private function readChoice(array $def, IncomingMessage $m): array
    {
        $value = $m->replyId ?? $this->matchOption($def['options'], (string) $m->text);

        return $value !== null && isset($def['options'][$value])
            ? [$value, null]
            : [null, 'Scegli una delle opzioni proposte.'];
    }

    private function readText(array $def, IncomingMessage $m): array
    {
        if ($m->type !== 'text') {
            return [null, 'Rispondimi con un messaggio di testo.'];
        }

        $value = trim($m->text);
        if ($def['strip_spaces'] ?? false) {
            $value = preg_replace('/\s+/', '', $value);
        }
        if ($def['upper'] ?? false) {
            $value = Str::upper($value);
        }

        $error = $def['error'] ?? 'Risposta non valida, riprova.';
        if (! Validator::make(['v' => $value], ['v' => $def['rules']])->passes()) {
            return [null, $error];
        }
        if (($def['checksum'] ?? null) === 'iban' && ! Iban::isValid($value)) {
            return [null, $error];
        }
        if (($def['derive'] ?? null) === 'codice_fiscale') {
            return $this->deriveFromCodiceFiscale($def, $value, $error);
        }

        return [$value, null];
    }

    private function matchOption(array $options, string $text): ?string
    {
        $needle = $this->normalize($text);
        if ($needle === '') {
            return null;
        }
        $keys = array_keys($options);
        foreach ($options as $id => $title) {
            if ($needle === $this->normalize((string) $id) || $needle === $this->normalize($title)) {
                return (string) $id;
            }
        }

        return ctype_digit($needle) && isset($keys[(int) $needle - 1]) ? (string) $keys[(int) $needle - 1] : null;
    }

    /** Nodo successivo: segue i salti, esegue i controlli automatici e salta le domande già note. */
    private function nextNode(array $def, Conversation $conv, array &$data, string $value): string
    {
        $node = $this->target($def, $conv, $data, $value);

        for ($i = 0; $i < 10; $i++) {
            $next = $this->def($conv->flow, $node);

            if ($next['type'] === 'check') {
                $node = $this->target($next, $conv, $data, $this->runCheck($next, $data));
            } elseif ($this->shouldSkip($next, $conv, $data)) {
                $node = $this->target($next, $conv, $data, '');
            } else {
                return $node;
            }
        }

        throw new \LogicException("Troppi salti automatici a partire da {$conv->flow}.{$conv->node}");
    }

    private function target(array $def, Conversation $conv, array $data, string $value): string
    {
        $next = $def['next'];
        if (is_string($next)) {
            return $next;
        }

        $by = $def['next_by'] ?? 'answer';
        $source = $conv->flow === 'richiesta' ? $data : ($conv->loanRequest->answers ?? []);
        $key = $by === 'answer' ? $value : (string) ($source[$by] ?? '');

        return $next[$key] ?? $next['*'] ?? throw new \LogicException("Salto non definito per '{$key}'");
    }

    private function shouldSkip(array $def, Conversation $conv, array $data): bool
    {
        $condition = $def['skip_if'] ?? null;

        return match (true) {
            $condition === 'privacy_received' => (bool) $conv->loanRequest?->privacy_received_at,
            is_string($condition) && str_starts_with($condition, 'filled:') => filled($data[substr($condition, 7)] ?? null),
            default => false,
        };
    }

    /** Controlli automatici fra una domanda e l'altra: restituiscono la chiave del salto da seguire. */
    private function runCheck(array $def, array &$data): string
    {
        if ($def['check'] === 'cf_names') {
            $mismatches = CodiceFiscale::mismatches($data['codice_fiscale'], $data['cognome'], $data['nome']);
            if (! $mismatches) {
                unset($data['_difformita']);

                return 'ok';
            }

            $labels = ['cognome' => 'cognome', 'nome' => 'nome'];
            $data['_difformita'] = array_map(fn (array $m) => sprintf(
                'Il %s «%s» darebbe «%s», ma il codice fiscale contiene «%s»',
                $labels[$m['field']], $data[$m['field']], $m['expected'], $m['found']
            ), $mismatches);

            return 'mismatch';
        }

        throw new \LogicException("Controllo sconosciuto: {$def['check']}");
    }

    /** @return array{0: ?string, 1: ?string, 2?: array<string,string>} */
    private function deriveFromCodiceFiscale(array $def, string $value, string $error): array
    {
        $info = CodiceFiscale::parse($value);
        if (! $info) {
            return [null, $error];
        }

        $birth = Carbon::createFromFormat('!d/m/Y', $info['birth_date']);
        if (isset($def['min_age']) && $birth->gt(today()->subYears($def['min_age']))) {
            return [null, $def['age_error'] ?? 'Il cliente non ha l\'età richiesta.'];
        }

        return [$value, null, array_filter([
            'data_nascita' => $info['birth_date'],
            'sesso' => $info['sex'],
            'luogo_nascita' => $info['place'],
        ])];
    }

    private function back(Conversation $conv): array
    {
        $history = $conv->history ?? [];
        if (! $history) {
            return [Reply::text('Sei già alla prima domanda.'), ...$this->prompt($conv)];
        }

        $previous = array_pop($history);
        $data = $conv->data ?? [];
        unset($data[$previous]);
        foreach ($this->def($conv->flow, $previous)['derives'] ?? [] as $key) {
            unset($data[$key]);
        }
        $conv->update(['node' => $previous, 'history' => $history, 'data' => $data]);

        return $this->prompt($conv);
    }

    private function finish(Conversation $conv, string $value): array
    {
        return match ($value) {
            'conferma' => $this->complete($conv),
            'modifica' => $this->restart($conv),
            default => $this->cancel($conv),
        };
    }

    private function complete(Conversation $conv): array
    {
        return $conv->flow === 'richiesta' ? $this->completeRichiesta($conv) : $this->completePerfezionamento($conv);
    }

    private function completeRichiesta(Conversation $conv): array
    {
        $data = $conv->data ?? [];
        $loan = LoanRequest::create([
            'code' => LoanRequestCode::next(),
            'agent_wa_number' => $conv->wa_number,
            'product' => $data['prodotto'],
            'status' => 'richiesta',
            'answers' => $data,
        ]);
        $conv->loan_request_id = $loan->id;
        $this->close($conv, 'completata');

        return [Reply::text("✅ Richiesta registrata.\n\nCodice pratica: *{$loan->code}*\n\nConservalo: ti servirà per perfezionare il finanziamento con i dati del cliente.")];
    }

    private function completePerfezionamento(Conversation $conv): array
    {
        $loan = $conv->loanRequest;
        $loan->update(['personal' => $conv->data ?? [], 'status' => 'perfezionata', 'perfected_at' => now()]);
        $this->close($conv, 'completata');

        return [Reply::text("✅ Pratica *{$loan->code}* perfezionata e inviata in istruttoria al mediatore creditizio.")];
    }

    /** Chiude la conversazione e cancella i dati in corso (i dati definitivi stanno nella pratica). */
    private function close(Conversation $conv, string $status): void
    {
        $conv->update(['status' => $status, 'data' => []]);
    }

    private function restart(Conversation $conv): array
    {
        $conv->update([
            'data' => [], 'history' => [],
            'node' => config("finanziamento.flows.{$conv->flow}.restart"),
        ]);

        return [Reply::text('Ricominciamo.'), ...$this->prompt($conv)];
    }

    private function cancel(Conversation $conv): array
    {
        $this->close($conv, 'annullata');

        return [Reply::text('Operazione annullata.'), $this->menu()];
    }

    /** @return Reply[] */
    private function prompt(Conversation $conv): array
    {
        $def = $this->def($conv->flow, $conv->node);
        $body = $def['prompt'];
        $data = $conv->data ?? [];
        if (($def['show_derived'] ?? false) && isset($data['data_nascita'])) {
            $body = 'Dal codice fiscale risulta: nato/a il '.$data['data_nascita']
                .(isset($data['luogo_nascita']) ? ' a '.$data['luogo_nascita'] : '')."\n\n".$body;
        }
        if (($def['show_difformita'] ?? false) && $def['type'] === 'choice' && ! empty($data['_difformita'])) {
            $body = "⚠️ I dati non coincidono con il codice fiscale:\n• ".implode("\n• ", $data['_difformita'])."\n\n".$body;
        }
        if ($def['prompt_summary'] ?? false) {
            $body = $this->describe($conv->loanRequest->answers, 'richiesta')."\n\n".$body;
        }

        return match ($def['type']) {
            'choice' => [Reply::choice($body, $def['options'])],
            'summary' => [Reply::text($this->summary($conv, $def)), Reply::choice($def['prompt'], $def['options'])],
            'file' => [Reply::text($body.(($def['optional'] ?? false) ? "\n\nScrivi «salta» per saltare." : ''))],
            default => [Reply::text($body)],
        };
    }

    private function summary(Conversation $conv, array $def): string
    {
        $text = "📋 *Riepilogo*\n\n".$this->describe($conv->data ?? [], $conv->flow);

        if (! empty($def['docs'])) {
            $have = $conv->loanRequest->attachments()->pluck('kind')->all();
            $text .= "\n\n*Documenti*\n";
            foreach ($def['docs'] as $kind => $label) {
                $text .= (in_array($kind, $have, true) ? '✅ ' : '➖ ').$label."\n";
            }
        }

        if (($def['show_difformita'] ?? false) && ! empty($conv->data['_difformita'])) {
            $text .= "\n\n⚠️ *Dati difformi da verificare*\n• ".implode("\n• ", $conv->data['_difformita'])
                ."\nSaranno segnalati al mediatore creditizio.";
        }

        return rtrim($text);
    }

    private function describe(array $answers, string $flow): string
    {
        return collect(LoanRequest::describe($answers, $flow))
            ->map(fn (string $value, string $label) => "• {$label}: {$value}")
            ->implode("\n");
    }

    private function def(string $flow, string $node): array
    {
        return config("finanziamento.flows.{$flow}.nodes.{$node}") ?? throw new \LogicException("Nodo {$flow}.{$node} inesistente");
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', $text))));
    }

    private function readCode(Conversation $conv, IncomingMessage $m): array
    {
        if ($m->type !== 'text') {
            return [null, 'Scrivi il codice della pratica.'];
        }

        $code = Str::upper(trim($m->text));
        $loan = LoanRequest::where('code', $code)->where('agent_wa_number', $conv->wa_number)->first();
        if (! $loan) {
            return [null, 'Codice non trovato. Controlla e riprova.'];
        }
        if ($loan->status === 'perfezionata') {
            return [null, 'Questa pratica è già stata perfezionata.'];
        }
        if ($loan->status === 'richiesta') {
            $loan->update(['status' => 'in_attesa_informativa']);
        }

        $conv->loan_request_id = $loan->id;
        $conv->setRelation('loanRequest', $loan);

        return [$code, null];
    }

    private function readFile(Conversation $conv, array $def, IncomingMessage $m): array
    {
        if ($m->type === 'text' && ($def['optional'] ?? false) && $this->normalize($m->text) === 'salta') {
            return ['salta', null];
        }
        if ($m->type !== 'media') {
            return [null, 'Invia una foto o un PDF.'];
        }
        if (! isset(self::ALLOWED_MIME[$m->mime])) {
            return [null, 'Formato non accettato: invia una foto (JPG, PNG) o un PDF.'];
        }

        $file = $this->client->downloadMedia($m->mediaId);
        if (! $file) {
            return [null, 'Non sono riuscito a scaricare il file. Riprova.'];
        }

        $loan = $conv->loanRequest;
        $path = "pratiche/{$loan->code}/{$def['kind']}-".Str::random(8).'.'.self::ALLOWED_MIME[$m->mime];
        Storage::disk('local')->put($path, $file['body']);
        $this->storedPaths[] = $path;
        $loan->attachments()->create([
            'kind' => $def['kind'], 'path' => $path, 'mime' => $m->mime,
            'wa_media_id' => $m->mediaId, 'received_at' => now(),
        ]);
        if ($def['kind'] === 'informativa') {
            $loan->update(['privacy_received_at' => now(), 'status' => 'informativa_ricevuta']);
        }

        return ['ricevuto', null];
    }

    private function stato(string $from): array
    {
        $loans = LoanRequest::where('agent_wa_number', $from)->latest('id')->limit(10)->get();
        if ($loans->isEmpty()) {
            return [Reply::text('Non hai ancora nessuna pratica.')];
        }

        $products = config('finanziamento.flows.richiesta.nodes.prodotto.options');
        $lines = $loans->map(fn ($l) => "• {$l->code} · ".($products[$l->product] ?? $l->product).' · '.str_replace('_', ' ', $l->status));

        return [Reply::text("📂 *Le tue pratiche*\n\n".$lines->implode("\n"))];
    }

    private function isStale(Conversation $conv): bool
    {
        return $conv->updated_at->lt(now()->subDay()) || ! empty($conv->data['_resume']);
    }

    private function resume(Conversation $conv, IncomingMessage $m): array
    {
        $data = $conv->data ?? [];

        if (! empty($data['_resume'])) {
            $choice = $m->replyId ?? $this->matchOption(['resume_si' => 'Continua', 'resume_no' => 'Ricomincia'], (string) $m->text);
            if ($choice === 'resume_si') {
                unset($data['_resume']);
                $conv->update(['data' => $data]);

                return $this->prompt($conv);
            }
            if ($choice === 'resume_no') {
                $this->close($conv, 'annullata');

                return [$this->menu()];
            }
        } else {
            $data['_resume'] = true;
            $conv->update(['data' => $data]);
        }

        return [Reply::choice('La conversazione precedente è ferma da più di 24 ore. Vuoi continuare?', [
            'resume_si' => 'Continua', 'resume_no' => 'Ricomincia',
        ])];
    }
}
