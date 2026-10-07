<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * All'inizio del perfezionamento il bot riassume i documenti del finanziamento e dà il link all'informativa.
     * Aggiunge il messaggio agli alberi già importati (produzione e copie di prova), senza toccare altre modifiche.
     * Ripetibile.
     */
    public function up(): void
    {
        $prompt = config('finanziamento.flows.perfezionamento.nodes.riepilogo_documenti.prompt');

        foreach (DB::table('flows')->where('code', 'perfezionamento')->get() as $flow) {
            $confirm = DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'conferma_pratica')->first();
            if (! $confirm) {
                continue;
            }

            if (! DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'riepilogo_documenti')->exists()) {
                DB::table('flow_nodes')->where('flow_id', $flow->id)->where('sort_order', '>', $confirm->sort_order)->increment('sort_order');

                $nodeId = DB::table('flow_nodes')->insertGetId([
                    'flow_id' => $flow->id, 'code' => 'riepilogo_documenti', 'type' => 'message', 'prompt' => $prompt,
                    'sort_order' => $confirm->sort_order + 1, 'skippable' => false, 'save' => false, 'jump_by' => 'answer',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('flow_node_jumps')->insert([
                    'flow_node_id' => $nodeId, 'when_value' => '*', 'go_to' => 'informativa', 'sort_order' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('flow_node_jumps')->where('flow_node_id', $confirm->id)->where('when_value', 'si')->where('go_to', 'informativa')
                ->update(['go_to' => 'riepilogo_documenti', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('flows')->where('code', 'perfezionamento')->get() as $flow) {
            $node = DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'riepilogo_documenti')->first();
            $confirm = DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'conferma_pratica')->first();
            if (! $node || ! $confirm) {
                continue;
            }

            DB::table('flow_node_jumps')->where('flow_node_id', $confirm->id)->where('when_value', 'si')->update(['go_to' => 'informativa']);
            DB::table('flow_nodes')->where('id', $node->id)->delete();
            DB::table('flow_nodes')->where('flow_id', $flow->id)->where('sort_order', '>', $node->sort_order)->decrement('sort_order');
        }
    }
};
