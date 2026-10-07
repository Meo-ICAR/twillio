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
     * @param  list<array{when: string, go_to: string}>|null  $jumps  salti proposti (null = quelli attuali)
     * @param  string|null  $jumpBy  da cosa dipendono i salti (null = valore attuale)
     * @return list<string>
     */
    public function nodeErrors(FlowNode $node, string $prompt, array $options, bool $skippable, array $checks = [], ?array $jumps = null, ?string $jumpBy = null): array
    {
        $errors = [];
        $jumps ??= $node->jumps->map(fn ($j) => ['when' => $j->when_value, 'go_to' => $j->go_to])->all();
        $jumpBy ??= $node->jump_by;

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
        }

        $errors = array_merge($errors, $this->jumpErrors($node, $options, $jumps, $jumpBy));

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

        if ($skippable && ! $this->canBeSkipped($node, $jumps)) {
            $errors[] = 'Questa domanda non si può rendere saltabile: manca un\'uscita predefinita (il salto «*»).';
        }

        return $errors;
    }

    /** @return list<string> */
    public function flowErrors(Flow $flow): array
    {
        $errors = [];
        $nodes = $flow->nodes()->with('jumps')->get()->keyBy('code');

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
            $targets[$code] = $node->jumps->pluck('go_to')->all();
            foreach ($targets[$code] as $target) {
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

    /**
     * @param  array<string,string>  $options
     * @param  list<array{when: string, go_to: string}>  $jumps
     * @return list<string>
     */
    private function jumpErrors(FlowNode $node, array $options, array $jumps, string $jumpBy): array
    {
        if ($node->type === 'summary') {
            return [];
        }

        if ($jumps === []) {
            return ['Una domanda deve avere almeno un salto, altrimenti il dialogo si ferma qui.'];
        }

        $errors = [];
        $codes = $node->flow->nodes()->pluck('code')->all();

        $seen = [];
        foreach ($jumps as $jump) {
            $when = (string) ($jump['when'] ?? '');
            $goTo = (string) ($jump['go_to'] ?? '');

            if ($when === '') {
                $errors[] = 'Un salto non ha la condizione.';
            } elseif (isset($seen[$when])) {
                $errors[] = "La condizione «{$when}» compare più di una volta.";
            }
            $seen[$when] = true;

            if (! in_array($goTo, $codes, true)) {
                $errors[] = "Il salto «{$when}» porta a «{$goTo}», che non è una domanda di questo percorso.";
            }
        }

        // Il dato può venire da una domanda di questo percorso o della richiesta (il perfezionamento salta in base al prodotto scelto lì).
        if ($jumpBy !== 'answer' && ! FlowNode::where('code', $jumpBy)->exists()) {
            $errors[] = "I salti dipendono da «{$jumpBy}», che non è una domanda dei percorsi.";
        }

        // Con i salti per risposta, ogni opzione deve avere il suo salto oppure esserci l'uscita predefinita.
        if ($jumpBy === 'answer' && ! isset($seen['*'])) {
            $missing = array_diff(array_map('strval', array_keys($options)), array_keys($seen));
            if ($missing) {
                $errors[] = 'Queste opzioni non hanno un salto: '.implode(', ', $missing).'. Aggiungi il salto oppure l\'uscita predefinita «*».';
            }
        }

        return $errors;
    }

    /** @param list<array{when: string, go_to: string}> $jumps */
    private function canBeSkipped(FlowNode $node, array $jumps): bool
    {
        if (! in_array($node->type, ['choice', 'text', 'file'], true)) {
            return false;
        }

        return collect($jumps)->contains(fn ($j) => ($j['when'] ?? null) === '*');
    }
}
