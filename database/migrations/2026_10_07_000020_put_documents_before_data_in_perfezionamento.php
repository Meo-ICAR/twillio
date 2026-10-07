<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Parametri dei passi introdotti dal nuovo ordine: si aggiungono solo se mancano, senza toccare quelli impostati a mano. */
    private const PARAMS = ['analyze', 'ack', 'skip_if', 'reask'];

    /** Passi che cambiano destinazione: passo => [destinazione di prima, destinazione nuova]. Si cambiano solo se sono ancora quelle di prima. */
    private const REDIRECTS = [
        'informativa' => ['codice_fiscale', 'doc_identita'],
        'doc_reddito' => ['riepilogo_p', 'attesa_documenti'],
        'data_assunzione' => ['doc_identita', 'riepilogo_p'],
        'partita_iva' => ['doc_identita', 'riepilogo_p'],
    ];

    /**
     * Perfezionamento con i documenti prima dei dati: dopo l'informativa si chiedono identità, codice fiscale e reddito,
     * si aspetta la lettura dell'AI, l'agente conferma i dati letti e le domande già note si saltano.
     * Porta alla nuova struttura gli alberi già importati (produzione e copie di prova) senza toccare il resto. Ripetibile.
     */
    public function up(): void
    {
        $def = config('finanziamento.flows.perfezionamento');
        if (! $def) {
            return;
        }

        foreach (DB::table('flows')->where('code', 'perfezionamento')->get() as $flow) {
            // Un albero che non riconosciamo si lascia com'è.
            $existing = DB::table('flow_nodes')->where('flow_id', $flow->id)->pluck('id', 'code');
            if (! $existing->has('informativa') || ! $existing->has('doc_reddito') || ! $existing->has('riepilogo_p')) {
                continue;
            }

            foreach (['attesa_documenti', 'rivedi_dati'] as $code) {
                $existing->has($code) || $this->createNode($flow->id, $code, $def['nodes'][$code]);
            }
            $nodes = DB::table('flow_nodes')->where('flow_id', $flow->id)->pluck('id', 'code');

            foreach (self::REDIRECTS as $code => [$old, $new]) {
                DB::table('flow_node_jumps')->where('flow_node_id', $nodes[$code])->where('when_value', '*')->where('go_to', $old)
                    ->update(['go_to' => $new, 'updated_at' => now()]);
            }

            foreach ($def['nodes'] as $code => $node) {
                if ($nodes->has($code)) {
                    $this->addParams($nodes[$code], array_intersect_key($node, array_flip(self::PARAMS)));
                }
            }

            $this->reorder($flow->id, array_keys($def['nodes']));
        }
    }

    public function down(): void
    {
        // Non si torna indietro: l'albero si ripristina dalla configurazione (flows:import --force).
    }

    /** @param array<string,mixed> $node */
    private function createNode(int $flowId, string $code, array $node): void
    {
        $columns = ['type', 'label', 'prompt', 'options', 'next', 'next_by', 'save', 'skippable', 'checks'];

        $id = DB::table('flow_nodes')->insertGetId([
            'flow_id' => $flowId, 'code' => $code, 'type' => $node['type'], 'label' => $node['label'] ?? null, 'prompt' => $node['prompt'],
            'sort_order' => (int) DB::table('flow_nodes')->where('flow_id', $flowId)->max('sort_order') + 1,
            'skippable' => $node['skippable'] ?? false, 'save' => $node['save'] ?? true, 'jump_by' => $node['next_by'] ?? 'answer',
            'params' => ($params = array_diff_key($node, array_flip($columns))) ? json_encode($params) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('flow_node_jumps')->insert(['flow_node_id' => $id, 'when_value' => '*', 'go_to' => $node['next'], 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $position = 0;
        foreach ($node['options'] ?? [] as $optionCode => $title) {
            DB::table('flow_node_options')->insert([
                'flow_node_id' => $id, 'code' => (string) $optionCode, 'title' => $title, 'sort_order' => ++$position,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string,mixed> $new */
    private function addParams(int $nodeId, array $new): void
    {
        if (! $new) {
            return;
        }

        $current = json_decode((string) DB::table('flow_nodes')->where('id', $nodeId)->value('params'), true) ?: [];
        $merged = $current + $new;

        if ($merged !== $current) {
            DB::table('flow_nodes')->where('id', $nodeId)->update(['params' => json_encode($merged), 'updated_at' => now()]);
        }
    }

    /**
     * L'ordine dei passi è quello della configurazione; quelli aggiunti a mano restano in coda, nel loro ordine.
     *
     * @param  list<string>  $configOrder
     */
    private function reorder(int $flowId, array $configOrder): void
    {
        $codes = DB::table('flow_nodes')->where('flow_id', $flowId)->orderBy('sort_order')->orderBy('id')->pluck('code')->all();
        $ordered = [...array_values(array_intersect($configOrder, $codes)), ...array_values(array_diff($codes, $configOrder))];

        foreach ($ordered as $i => $code) {
            DB::table('flow_nodes')->where('flow_id', $flowId)->where('code', $code)->update(['sort_order' => $i + 1]);
        }
    }
};
