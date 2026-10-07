<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConversationEngine
{
    private const PRIVACY_WARNING = '⚠️ Non inserire dati identificativi del cliente (nome, codice fiscale, telefono, email, P.IVA). In questa fase servono solo dati di profilo.';

    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private SensitiveDataGuard $guard,
        private WhatsAppClient $client,
    ) {}

    /** @return Reply[] */
    public function handle(IncomingMessage $m): array
    {
        $conv = Conversation::with('loanRequest')
            ->where('wa_number', $m->from)->where('status', 'attiva')->latest('id')->first();
        $command = $m->type === 'text' ? $this->normalize($m->text) : null;

        if (in_array($command, ['annulla', 'menu'], true)) {
            $conv?->update(['status' => 'annullata']);

            return $command === 'annulla' ? [Reply::text('Operazione annullata.'), $this->menu()] : [$this->menu()];
        }
        if (! $conv) {
            return $this->fromMenu($m);
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

        [$value, $error] = $this->read($conv, $def, $m);
        if ($value === null) {
            return [Reply::text($error), ...$this->prompt($conv)];
        }
        if ($def['type'] === 'summary') {
            return $this->finish($conv, $value);
        }

        $data = $conv->data ?? [];
        if ($def['save'] ?? true) {
            $data[$conv->node] = $value;
        }
        $history = $conv->history ?? [];
        $history[] = $conv->node;

        $conv->update([
            'data' => $data,
            'history' => $history,
            'node' => $this->nextNode($def, $conv, $data, $value),
        ]);

        return $this->prompt($conv);
    }

    /** @return array{0: ?string, 1: ?string} [valore, errore] */
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

        return Validator::make(['v' => $value], ['v' => $def['rules']])->passes()
            ? [$value, null]
            : [null, $def['error'] ?? 'Risposta non valida, riprova.'];
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

    private function nextNode(array $def, Conversation $conv, array $data, string $value): string
    {
        $next = $def['next'];
        if (is_string($next)) {
            return $this->skip($conv, $next);
        }

        $by = $def['next_by'] ?? 'answer';
        $source = $conv->flow === 'richiesta' ? $data : ($conv->loanRequest->answers ?? []);
        $key = $by === 'answer' ? $value : (string) ($source[$by] ?? '');

        return $this->skip($conv, $next[$key] ?? $next['*'] ?? throw new \LogicException("Salto non definito per '{$key}'"));
    }

    private function skip(Conversation $conv, string $node): string
    {
        $def = $this->def($conv->flow, $node);
        if (($def['skip_if'] ?? null) === 'privacy_received' && $conv->loanRequest?->privacy_received_at) {
            return $this->nextNode($def, $conv, [], '');
        }

        return $node;
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
        $data = $conv->data ?? [];
        $loan = LoanRequest::create([
            'code' => LoanRequestCode::next(),
            'agent_wa_number' => $conv->wa_number,
            'product' => $data['prodotto'],
            'status' => 'richiesta',
            'answers' => $data,
        ]);
        $conv->update(['status' => 'completata', 'loan_request_id' => $loan->id]);

        return [Reply::text("✅ Richiesta registrata.\n\nCodice pratica: *{$loan->code}*\n\nConservalo: ti servirà per perfezionare il finanziamento con i dati del cliente.")];
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
        $conv->update(['status' => 'annullata']);

        return [Reply::text('Operazione annullata.'), $this->menu()];
    }

    /** @return Reply[] */
    private function prompt(Conversation $conv): array
    {
        $def = $this->def($conv->flow, $conv->node);
        $body = $def['prompt'];
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

        return rtrim($text);
    }

    private function describe(array $answers, string $flow): string
    {
        $lines = [];
        foreach ($answers as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $node = config("finanziamento.flows.{$flow}.nodes.{$key}") ?? [];
            $lines[] = '• '.($node['label'] ?? $key).': '.($node['options'][$value] ?? $value);
        }

        return implode("\n", $lines);
    }

    private function def(string $flow, string $node): array
    {
        return config("finanziamento.flows.{$flow}.nodes.{$node}") ?? throw new \LogicException("Nodo {$flow}.{$node} inesistente");
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', $text))));
    }
}
