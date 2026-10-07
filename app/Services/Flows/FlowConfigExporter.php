<?php

namespace App\Services\Flows;

use App\Models\FlowCheck;

/**
 * Ricrea dalle tabelle il file di configurazione dei percorsi (config/finanziamento.php):
 * controlli attivi, menu e percorsi di produzione. Il file si ricarica con `flows:import --force`.
 */
class FlowConfigExporter
{
    /** Ordine delle chiavi di una domanda nel file; le altre (parametri) seguono. */
    private const NODE_KEYS = ['type', 'label', 'prompt', 'options', 'next', 'next_by', 'save', 'skippable', 'checks'];

    private const FLOW_KEYS = ['start', 'restart', 'header', 'labels', 'nodes'];

    public function export(?\DateTimeInterface $at = null): string
    {
        $at ??= now();

        $data = [
            'checks' => $this->checks(),
            'menu' => config('finanziamento.menu'),
            'flows' => $this->flows(),
        ];

        return "<?php\n\n"
            ."// Generato il {$at->format('d/m/Y H:i')} da «php artisan flows:export» a partire dalle tabelle dei percorsi.\n"
            ."// Contiene i percorsi di produzione (non le copie di prova) e i controlli attivi.\n"
            ."// Si ricarica con «php artisan flows:import --force», che sostituisce i percorsi nel database.\n\n"
            .'return '.$this->render($data, 0).";\n";
    }

    /** @return array<string,ClassReference> */
    private function checks(): array
    {
        return FlowCheck::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (FlowCheck $c) => [$c->code => new ClassReference($c->class)])->all();
    }

    /** @return array<string,array<string,mixed>> */
    private function flows(): array
    {
        $flows = [];

        foreach (app(FlowRepository::class)->production() as $code => $flow) {
            $flow['nodes'] = array_map(fn (array $node) => $this->ordered($node, self::NODE_KEYS), $flow['nodes']);
            $flows[$code] = $this->ordered($flow, self::FLOW_KEYS);
        }

        return $flows;
    }

    /** @param list<string> $first */
    private function ordered(array $definition, array $first): array
    {
        $head = [];
        foreach ($first as $key) {
            if (array_key_exists($key, $definition)) {
                $head[$key] = $definition[$key];
            }
        }

        return $head + $definition;
    }

    private function render(mixed $value, int $indent): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_string($value) => $this->quote($value),
            $value instanceof ClassReference => '\\'.ltrim($value->class, '\\').'::class',
            is_array($value) => $this->renderArray($value, $indent),
            default => throw new \InvalidArgumentException('Valore non esportabile: '.get_debug_type($value)),
        };
    }

    private function renderArray(array $array, int $indent): string
    {
        if ($array === []) {
            return '[]';
        }

        $list = array_is_list($array);
        $scalars = ! array_filter($array, fn ($v) => is_array($v));

        // Gli elenchi corti di soli valori semplici stanno su una riga.
        if ($scalars && count($array) <= 3) {
            $inline = '['.implode(', ', array_map(fn ($k, $v) => ($list ? '' : $this->key($k).' => ').$this->render($v, 0), array_keys($array), $array)).']';
            if (mb_strlen($inline) <= 90 && ! str_contains($inline, "\n")) {
                return $inline;
            }
        }

        $pad = str_repeat(' ', $indent + 4);
        $lines = [];
        foreach ($array as $key => $item) {
            $lines[] = $pad.($list ? '' : $this->key($key).' => ').$this->render($item, $indent + 4).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat(' ', $indent).']';
    }

    private function key(int|string $key): string
    {
        return is_int($key) ? (string) $key : $this->quote($key);
    }

    /** Testo tra apici semplici; con a capo o tabulazioni tra doppi apici, così resta su una riga. */
    private function quote(string $text): string
    {
        if (preg_match('/[\n\r\t]/', $text)) {
            return '"'.strtr($text, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
        }

        return "'".strtr($text, ['\\' => '\\\\', "'" => "\\'"])."'";
    }
}
