<?php

namespace App\Services\Flows;

use App\Models\Flow;
use Illuminate\Support\Facades\DB;

/** Porta nelle tabelle l'albero scritto in config/finanziamento.php. */
class FlowImporter
{
    private const FLOW_NAMES = [
        'richiesta' => 'Richiedi Finanziamento',
        'perfezionamento' => 'Perfeziona Finanziamento',
        'documenti' => 'Stato Pratiche · Carica documenti',
    ];

    /** Colonne della domanda: tutto il resto va in `params`. */
    private const COLUMNS = ['type', 'label', 'prompt', 'options', 'next', 'save', 'skippable', 'checks'];

    /**
     * @param  bool  $force  se vero ripristina i percorsi già presenti, perdendo le modifiche fatte a mano
     * @return int percorsi importati
     */
    public function import(bool $force = false): int
    {
        $imported = 0;

        foreach (config('finanziamento.flows') as $code => $def) {
            if (! $force && Flow::where('code', $code)->exists()) {
                continue;
            }

            DB::transaction(function () use ($code, $def) {
                $flow = Flow::updateOrCreate(['code' => $code], [
                    'name' => self::FLOW_NAMES[$code] ?? $code,
                    'header' => $def['header'] ?? null,
                    'start' => $def['start'],
                    'restart' => $def['restart'],
                    'labels' => $def['labels'] ?? null,
                    'is_active' => true,
                ]);
                $flow->nodes()->get()->each->delete();

                $order = 0;
                foreach ($def['nodes'] as $nodeCode => $node) {
                    $next = $node['next'] ?? null;
                    $created = $flow->nodes()->create([
                        'code' => $nodeCode,
                        'type' => $node['type'],
                        'label' => $node['label'] ?? null,
                        'prompt' => $node['prompt'],
                        'sort_order' => ++$order,
                        'skippable' => $node['skippable'] ?? false,
                        'save' => $node['save'] ?? true,
                        'next_to' => is_string($next) ? $next : null,
                        'next_map' => is_array($next) ? $next : null,
                        'params' => array_diff_key($node, array_flip(self::COLUMNS)) ?: null,
                        'checks' => $node['checks'] ?? null,
                    ]);

                    $position = 0;
                    foreach ($node['options'] ?? [] as $optionCode => $title) {
                        $created->options()->create(['code' => (string) $optionCode, 'title' => $title, 'sort_order' => ++$position]);
                    }
                }
            });

            $imported++;
        }

        return $imported;
    }
}
