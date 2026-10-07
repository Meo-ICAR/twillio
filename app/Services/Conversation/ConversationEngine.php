<?php

namespace App\Services\Conversation;

use App\Jobs\AnalyzeAttachment;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Models\User;
use App\Services\Checks\CheckContext;
use App\Services\Checks\CheckRegistry;
use App\Services\Documents\DocumentReader;
use App\Services\Flows\FlowRepository;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConversationEngine
{
    private const PRIVACY_WARNING = '⚠️ Non inserire dati identificativi del cliente (nome, codice fiscale, telefono, email, P.IVA). In questa fase servono solo dati di profilo.';

    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** @var string[] file salvati in questa richiesta, da eliminare se l'invio fallisce */
    private array $storedPaths = [];

    /** @var Reply[] messaggi senza risposta incontrati nel percorso, da mostrare prima della prossima domanda */
    private array $notices = [];

    public function __construct(
        private SensitiveDataGuard $guard,
        private WhatsAppClient $client,
        private DocumentReader $reader,
        private FlowRepository $flows,
        private CheckRegistry $checks,
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

        // Una conversazione di prova prosegue sempre sulla copia di prova del percorso; le altre sulla produzione.
        $this->flows->setTest((bool) $conv?->is_test);
        $this->notices = [];

        try {
            return $this->dispatch($m, $conv);
        } finally {
            $this->flows->setTest(false);
        }
    }

    /** @return Reply[] */
    private function dispatch(IncomingMessage $m, ?Conversation $conv): array
    {
        $command = $m->type === 'text' ? $this->normalize($m->text) : null;

        if (in_array($command, ['annulla', 'menu'], true)) {
            $conv && $this->close($conv, 'annullata');

            return $command === 'annulla' ? [Reply::text('Operazione annullata.'), $this->menu($m->from)] : [$this->menu($m->from)];
        }
        if (! $conv) {
            return $this->fromMenu($m);
        }
        if (! $this->flows->node($conv->flow, $conv->node)) {
            $this->close($conv, 'annullata');

            return [Reply::text('La conversazione non è più valida: ricominciamo dal menu.'), $this->menu($m->from)];
        }
        if ($this->isStale($conv)) {
            return $this->resume($conv, $m);
        }
        if ($command === 'indietro') {
            return $this->back($conv);
        }

        return $this->answer($conv, $m);
    }

    /** Il menu; chi ha il numero associato a un utente vede anche le voci di prova dei percorsi che hanno una copia. */
    private function menu(?string $waNumber = null): Reply
    {
        $options = config('finanziamento.menu.options');

        if ($waNumber !== null && User::hasTesterNumber($waNumber)) {
            $hasCopy = $this->flows->testFlowCodes();
            foreach (config('finanziamento.menu.test') as $flow => [$id, $title]) {
                if (in_array($flow, $hasCopy, true)) {
                    $options[$id] = $title;
                }
            }
        }

        return Reply::choice(config('finanziamento.menu.body'), $options);
    }

    private function fromMenu(IncomingMessage $m): array
    {
        $choice = $m->replyId ?? match ($this->normalize((string) $m->text)) {
            '1', 'richiedi', 'richiedi finanziamento' => 'menu_richiedi',
            '2', 'perfeziona', 'perfeziona finanziamento' => 'menu_perfeziona',
            '3', 'stato', 'stato pratiche' => 'menu_stato',
            default => null,
        };

        // Le voci di prova valgono solo per chi è associato a un utente e solo se il percorso ha una copia di prova.
        foreach (config('finanziamento.menu.test') as $flow => [$id]) {
            if ($choice === $id && User::hasTesterNumber($m->from) && in_array($flow, $this->flows->testFlowCodes(), true)) {
                $this->flows->setTest(true);

                return $flow === 'documenti' ? $this->startDocuments($m->from) : $this->start($m->from, $flow);
            }
        }

        return match ($choice) {
            'menu_richiedi' => $this->start($m->from, 'richiesta'),
            'menu_perfeziona' => $this->start($m->from, 'perfezionamento'),
            'menu_stato' => $this->startDocuments($m->from),
            default => [$this->menu($m->from)],
        };
    }

    private function start(string $from, string $flow): array
    {
        $def = $this->flows->flow($flow) ?? throw new \LogicException("Percorso {$flow} inesistente o disattivato");
        $conv = Conversation::create([
            'wa_number' => $from, 'flow' => $flow, 'data' => [], 'history' => [], 'node' => $def['start'],
            'is_test' => $this->flows->isTest(),
        ]);

        // L'intestazione (facoltativa) si mostra una sola volta, all'inizio del dialogo.
        $header = trim((string) ($def['header'] ?? ''));

        return [...($header !== '' ? [Reply::text($header)] : []), ...$this->prompt($conv)];
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

        if ($m->type === 'text' && $this->canSkip($def) && $this->normalize($m->text) === 'salta') {
            return $this->skip($conv, $def);
        }

        $result = $this->read($conv, $def, $m);
        [$value, $error] = $result;
        $extra = [];
        if ($value === null) {
            return [Reply::text($error), ...$this->prompt($conv)];
        }
        if (! empty($def['checks']) && in_array($def['type'], ['text', 'choice'], true)) {
            $checked = $this->runChecks($def, (string) $value, $conv->data ?? []);
            if ($checked['error'] !== null) {
                return [Reply::text($checked['error']), ...$this->prompt($conv)];
            }
            $extra = $checked['derived'];
        }
        if ($def['type'] === 'summary') {
            return $this->finish($conv, $value);
        }

        $data = $conv->data ?? [];
        foreach ($this->derivedKeys($def) as $key) {
            unset($data[$key]);
        }
        if ($def['save'] ?? true) {
            $data[$conv->node] = $value;
        }
        $data = array_merge($data, $extra);
        $history = $conv->history ?? [];
        $history[] = $conv->node;

        if ($def['binds_loan'] ?? false) {
            $loan = LoanRequest::where('code', $value)->where('agent_wa_number', $conv->wa_number)->where('is_test', $this->flows->isTest())->firstOrFail();
            $conv->loan_request_id = $loan->id;
            $conv->setRelation('loanRequest', $loan);
        }

        $next = $this->nextNode($def, $conv, $data, $value);
        $conv->update(['data' => $data, 'history' => $history, 'node' => $next]);

        $replies = [...$this->takeNotices(), ...$this->prompt($conv)];
        if ($def['type'] === 'file' && ($def['ack'] ?? false)) {
            $note = '✅ Documento ricevuto.'.($this->reader->enabled() ? ' Lo controllo e ti scrivo l\'esito tra poco.' : '');
            array_unshift($replies, Reply::text($note));
        }

        return $replies;
    }

    /** Una domanda si salta solo se è marcata saltabile e ha un'uscita predefinita (nodo fisso oppure '*' nei salti). */
    private function canSkip(array $def): bool
    {
        if (! ($def['skippable'] ?? false) || ! in_array($def['type'], ['choice', 'text', 'file'], true)) {
            return false;
        }

        $next = $def['next'] ?? null;

        return is_string($next) || (is_array($next) && isset($next['*']));
    }

    /** Salta la domanda: non salva nulla e prosegue dall'uscita predefinita. */
    private function skip(Conversation $conv, array $def): array
    {
        $data = $conv->data ?? [];
        $history = $conv->history ?? [];
        $history[] = $conv->node;

        $next = $this->nextNode($def, $conv, $data, '');
        $conv->update(['data' => $data, 'history' => $history, 'node' => $next]);

        return [...$this->takeNotices(), ...$this->prompt($conv)];
    }

    /** @return array{0: ?string, 1: ?string, 2?: array<string,string>} [valore, errore, dati ricavati] */
    private function read(Conversation $conv, array $def, IncomingMessage $m): array
    {
        return match ($def['type']) {
            'choice', 'summary' => $this->readChoice($conv, $def, $m),
            'text' => $this->readText($def, $m),
            'code' => $this->readCode($conv, $m),
            'file' => $this->readFile($conv, $def, $m),
        };
    }

    private function readChoice(Conversation $conv, array $def, IncomingMessage $m): array
    {
        $options = $this->optionsFor($conv, $def);
        $value = $m->replyId ?? $this->matchOption($options, (string) $m->text);

        if ($value === null || ! isset($options[$value])) {
            return [null, 'Scegli una delle opzioni proposte.'];
        }
        if (($def['guards'][$value] ?? null) === 'privacy_received' && ! $conv->loanRequest?->privacy_received_at) {
            return [null, 'Per caricare i documenti serve prima l\'informativa firmata dal cliente: usa Perfeziona Finanziamento.'];
        }

        return [$value, null];
    }

    /** Opzioni di un nodo: fisse da config oppure ricavate dai dati (le pratiche dell'agente). */
    private function optionsFor(Conversation $conv, array $def): array
    {
        if (($def['options_from'] ?? null) === 'agent_loans') {
            return $this->agentLoans($conv->wa_number)->mapWithKeys(fn (LoanRequest $l) => [$l->code => $l->code])->all();
        }

        if (($def['options_from'] ?? null) === 'loan_documents') {
            $pending = $conv->loanRequest ? PraticaDocument::populate($conv->loanRequest)->where('status', '!=', 'ok')->take(9) : collect();

            return $pending->mapWithKeys(fn (PraticaDocument $d) => [$d->code => mb_substr($d->name, 0, 24)])->all() + ($def['options'] ?? []);
        }

        return $def['options'] ?? [];
    }

    /** @return Collection<int,LoanRequest> */
    private function agentLoans(string $waNumber)
    {
        return LoanRequest::where('agent_wa_number', $waNumber)->where('is_test', $this->flows->isTest())->latest('id')->limit(10)->get();
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
            } elseif ($next['type'] === 'message') {
                $this->notices[] = Reply::text($this->renderMessage($next, $conv));
                $node = $this->target($next, $conv, $data, '');
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

    /** @return Reply[] */
    private function takeNotices(): array
    {
        $notices = $this->notices;
        $this->notices = [];

        return $notices;
    }

    /** Testo di un messaggio senza risposta, con i segnaposto sostituiti. */
    private function renderMessage(array $def, Conversation $conv): string
    {
        $loan = $conv->loanRequest;

        return strtr($def['prompt'], [
            '{codice}' => $loan?->code ?? '',
            '{prodotto}' => $loan ? (LoanRequest::productLabels()[$loan->product] ?? $loan->product) : '',
            '{documenti}' => $loan ? $this->requiredDocuments($loan) : '',
            '{informativa_url}' => rtrim((string) config('app.url'), '/').'/privacy',
        ]);
    }

    /** I documenti da preparare per il finanziamento (obbligatori e facoltativi del catalogo), con la descrizione. */
    private function requiredDocuments(LoanRequest $loan): string
    {
        $slots = PraticaDocument::populate($loan)->load('template');

        $sections = [];
        foreach (['obbligatorio' => 'Obbligatori', 'facoltativo' => 'Facoltativi'] as $requirement => $title) {
            $lines = $slots->where('requirement', $requirement)
                ->map(fn (PraticaDocument $d) => '• '.$d->name.($d->template?->description ? " — {$d->template->description}" : ''))->all();
            if ($lines) {
                $sections[] = "*{$title}*\n".implode("\n", $lines);
            }
        }

        if (! $sections) {
            return 'Nessun documento previsto per questo finanziamento: l\'istruttore ti dirà se serve altro.';
        }

        return implode("\n\n", $sections)."\n\nSe servono approfondimenti, l'istruttore potrà chiederti altri documenti.";
    }

    /**
     * Esegue in ordine i controlli agganciati alla domanda: il primo che restituisce false ferma tutto
     * e la domanda si ripete. I dati ricavati si tengono solo se passano tutti.
     *
     * @return array{error: ?string, derived: array<string,string>}
     */
    private function runChecks(array $def, string $value, array $data): array
    {
        $ctx = new CheckContext($data);

        foreach ($def['checks'] as $entry) {
            $name = is_array($entry) ? $entry['name'] : $entry;
            $params = is_array($entry) ? array_diff_key($entry, ['name' => 1]) : [];

            $check = $this->checks->get($name);
            if (! $check) {
                Log::error('Controllo sconosciuto agganciato a una domanda', ['check' => $name]);

                return ['error' => 'Il controllo di questa risposta non è disponibile al momento. Riprova più tardi o scrivi «menu».', 'derived' => []];
            }

            if (! $check->passes($value, $ctx->withParams($params))) {
                return ['error' => $ctx->error() ?? $def['error'] ?? 'Risposta non valida, riprova.', 'derived' => []];
            }
        }

        return ['error' => null, 'derived' => $ctx->derived()];
    }

    /**
     * Chiavi dei dati ricavati dai controlli della domanda.
     *
     * @return list<string>
     */
    private function derivedKeys(array $def): array
    {
        $keys = [];
        foreach ($def['checks'] ?? [] as $entry) {
            $check = $this->checks->get(is_array($entry) ? $entry['name'] : $entry);
            $keys = array_merge($keys, $check?->derives() ?? []);
        }

        return array_values(array_unique($keys));
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
        foreach ($this->derivedKeys($this->def($conv->flow, $previous)) as $key) {
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
            'code' => LoanRequestCode::next($this->flows->isTest()),
            'is_test' => $this->flows->isTest(),
            'agent_wa_number' => $conv->wa_number,
            'product' => $data['prodotto'],
            'status' => 'richiesta',
            'answers' => $data,
        ]);
        PraticaDocument::populate($loan);
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
            'node' => $this->flows->flow($conv->flow)['restart'],
        ]);

        return [Reply::text('Ricominciamo.'), ...$this->prompt($conv)];
    }

    private function cancel(Conversation $conv): array
    {
        $this->close($conv, 'annullata');

        return [Reply::text('Operazione annullata.'), $this->menu($conv->wa_number)];
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
        if (($def['prompt_with'] ?? null) === 'loans_list') {
            $body .= "\n\n".$this->agentLoans($conv->wa_number)->map(fn (LoanRequest $l) => $this->loanLine($l))->implode("\n");
        }
        $before = [];
        if (($def['prompt_with'] ?? null) === 'doc_checklist' && $conv->loanRequest) {
            $before[] = Reply::text($this->checklist($conv->loanRequest));
        }
        if ($def['prompt_summary'] ?? false) {
            $body = $this->describe($conv->loanRequest->answers, 'richiesta')."\n\n".$body;
        }
        if ($this->canSkip($def)) {
            $body .= "\n\nScrivi «salta» per saltare.";
        }

        return match ($def['type']) {
            'choice' => [...$before, Reply::choice($body, $this->optionsFor($conv, $def))],
            'summary' => [Reply::text($this->summary($conv, $def)), Reply::choice($def['prompt'], $def['options'])],
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
        return $this->flows->node($flow, $node) ?? throw new \LogicException("Nodo {$flow}.{$node} inesistente");
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
        $loan = LoanRequest::where('code', $code)->where('agent_wa_number', $conv->wa_number)->where('is_test', $this->flows->isTest())->first();
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
        $kind = isset($def['kind_from']) ? ($conv->data[$def['kind_from']] ?? null) : $def['kind'];
        $path = "pratiche/{$loan->code}/{$kind}-".Str::random(8).'.'.self::ALLOWED_MIME[$m->mime];
        Storage::disk('local')->put($path, $file['body']);
        $this->storedPaths[] = $path;
        $slot = $kind === 'informativa' ? null : PraticaDocument::populate($loan)->firstWhere('code', $kind);
        $attachment = $loan->attachments()->create([
            'kind' => $kind, 'path' => $path, 'mime' => $m->mime, 'pratica_document_id' => $slot?->id,
            'wa_media_id' => $m->mediaId, 'received_at' => now(),
        ]);
        $slot?->update(['status' => 'ricevuto', 'received_at' => now()]);
        if ($def['analyze'] ?? false) {
            AnalyzeAttachment::dispatchAfterResponse($attachment->id);
        }
        if ($kind === 'informativa') {
            $loan->update(['privacy_received_at' => now(), 'status' => 'informativa_ricevuta']);
        }

        return ['ricevuto', null];
    }

    /** "Stato Pratiche": elenco delle pratiche dell'agente, da cui si caricano i documenti. */
    private function startDocuments(string $from): array
    {
        if ($this->agentLoans($from)->isEmpty()) {
            return [Reply::text('Non hai ancora nessuna pratica.')];
        }

        return $this->start($from, 'documenti');
    }

    private function loanLine(LoanRequest $loan): string
    {
        return "• {$loan->code} · ".(LoanRequest::productLabels()[$loan->product] ?? $loan->product).' · '.str_replace('_', ' ', $loan->status);
    }

    /** Dettaglio della pratica con i suoi documenti, raggruppati per tipo, con stato e ultima annotazione. */
    private function checklist(LoanRequest $loan): string
    {
        $slots = PraticaDocument::populate($loan);

        $sections = [];
        foreach (['obbligatorio' => 'Obbligatori', 'facoltativo' => 'Facoltativi', 'integrativo' => 'Integrazioni richieste'] as $requirement => $title) {
            $lines = $slots->where('requirement', $requirement)->map(fn (PraticaDocument $d) => $this->slotLine($d))->all();
            if ($lines) {
                $sections[] = "*{$title}*\n".implode("\n", $lines);
            }
        }

        $text = '📂 *'.$loan->code.'* · '.(LoanRequest::productLabels()[$loan->product] ?? $loan->product)
            .' · '.str_replace('_', ' ', $loan->status)."\n\n".implode("\n\n", $sections);

        if (! $loan->privacy_received_at) {
            $text .= "\n\nPer caricare documenti serve prima l'informativa firmata: usa Perfeziona Finanziamento.";
        }

        return $text;
    }

    private function slotLine(PraticaDocument $doc): string
    {
        $note = $doc->lastAnnotation();
        $note = $note ? ' — '.Str::limit($note, 90) : '';

        return match ($doc->status) {
            'ok' => "✅ {$doc->name}",
            'ricevuto' => "📎 {$doc->name}: ricevuto, in verifica",
            'rejected' => "⚠️ {$doc->name}: da correggere{$note}",
            'integrazione_richiesta' => "📝 {$doc->name}: richiesto{$note}",
            default => "➖ {$doc->name}: mancante",
        };
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

                return [$this->menu($conv->wa_number)];
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
