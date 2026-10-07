<?php

namespace App\Services\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Checks\CheckRegistry;

/** Controlli che impediscono di salvare un albero che bloccherebbe gli agenti o che WhatsApp rifiuterebbe. */
class FlowValidator
{
    public const MAX_PROMPT = 900;

    public const MAX_OPTIONS = 10;

    public const MAX_OPTION_TITLE = 24;

    public const MAX_HEADER = 1000;

    /**
     * Errori della domanda con i valori che si vogliono salvare.
     *
     * @param  array<string,string>  $options  codice => titolo
     * @param  array<int,string|array<string,mixed>>  $checks  controlli agganciati: nome, oppure ['name' => ..., ...parametri]
     * @return list<string>
     */
    public function nodeErrors(FlowNode $node, string $prompt, array $options, bool $skippable, array $checks = []): array
    {
        $errors = [];

        if (trim($prompt) === '') {
            $errors[] = 'Il testo della domanda non può essere vuoto.';
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            $errors[] = 'Il testo della domanda supera '.self::MAX_PROMPT.' caratteri.';
        }

        if (in_array($node->type, ['choice', 'summary'], true)) {
            if ($options === [] && ! ($node->params['options_from'] ?? null)) {
                $errors[] = 'Serve almeno un\'opzione di risposta.';
            }
            if (count($options) > self::MAX_OPTIONS) {
                $errors[] = 'Le opzioni sono più di '.self::MAX_OPTIONS.': è il limite delle liste di WhatsApp.';
            }
            foreach ($options as $code => $title) {
                if (trim((string) $title) === '') {
                    $errors[] = "L'opzione «{$code}» non ha un titolo.";
                } elseif (mb_strlen($title) > self::MAX_OPTION_TITLE) {
                    $errors[] = "L'opzione «{$code}» ha un titolo oltre ".self::MAX_OPTION_TITLE.' caratteri (limite di WhatsApp).';
                }
            }

            // I salti per risposta vanno tenuti allineati alle opzioni (quelli per prodotto non dipendono da esse).
            $byAnswer = ($node->params['next_by'] ?? 'answer') === 'answer';
            if ($byAnswer && $node->next_map !== null && ! isset($node->next_map['*'])) {
                $missing = array_diff(array_map('strval', array_keys($options)), array_map('strval', array_keys($node->next_map)));
                if ($missing) {
                    $errors[] = 'Queste opzioni non hanno un salto configurato: '.implode(', ', $missing).'. Aggiungile anche ai salti della domanda.';
                }
            }
        }

        if ($checks) {
            if (! in_array($node->type, ['text', 'choice'], true)) {
                $errors[] = 'I controlli si agganciano solo a domande di testo o a scelta.';
            }
            foreach ($checks as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? null) : $entry;
                if (! is_string($name) || $name === '') {
                    $errors[] = 'Un controllo agganciato non ha il nome.';
                } elseif (! app(CheckRegistry::class)->get($name)) {
                    $errors[] = "Il controllo «{$name}» non esiste.";
                }
            }
        }

        if ($skippable && ! $this->canBeSkipped($node)) {
            $errors[] = 'Questa domanda non si può rendere saltabile: manca un\'uscita predefinita (un salto fisso oppure il salto «*»).';
        }

        return $errors;
    }

    /** @return list<string> */
    public function flowErrors(Flow $flow): array
    {
        $errors = [];
        $nodes = $flow->nodes()->get()->keyBy('code');

        if (mb_strlen((string) $flow->header) > self::MAX_HEADER) {
            $errors[] = 'L\'intestazione supera '.self::MAX_HEADER.' caratteri.';
        }
        foreach (['start' => 'La domanda iniziale', 'restart' => 'La domanda di ripartenza'] as $field => $label) {
            if (! $nodes->has($flow->{$field})) {
                $errors[] = "{$label} «{$flow->{$field}}» non esiste.";
            }
        }

        $targets = [];
        foreach ($nodes as $code => $node) {
            $to = $node->next_map !== null ? array_values($node->next_map) : array_filter([$node->next_to]);
            $targets[$code] = $to;
            foreach ($to as $target) {
                if (! $nodes->has($target)) {
                    $errors[] = "La domanda «{$code}» rimanda a «{$target}», che non esiste.";
                }
            }
        }

        $seen = [];
        $queue = array_filter([$flow->start, $flow->restart]);
        while ($queue) {
            $code = array_shift($queue);
            if (isset($seen[$code]) || ! $nodes->has($code)) {
                continue;
            }
            $seen[$code] = true;
            array_push($queue, ...($targets[$code] ?? []));
        }
        $unreachable = array_diff($nodes->keys()->all(), array_keys($seen));
        if ($unreachable) {
            $errors[] = 'Domande che nessun salto raggiunge: '.implode(', ', $unreachable).'.';
        }

        return array_values(array_unique($errors));
    }

    private function canBeSkipped(FlowNode $node): bool
    {
        if (! in_array($node->type, ['choice', 'text', 'file'], true)) {
            return false;
        }

        return $node->next_to !== null || isset($node->next_map['*']);
    }
}
