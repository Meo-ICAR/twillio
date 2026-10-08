<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dopo l'email del cliente il bot chiede se i documenti si possono chiedere anche direttamente a lui.
     * Aggiunge il passo agli alberi già importati (produzione e prove) senza toccare altre modifiche. Ripetibile.
     */
    public function up(): void
    {
        $node = config('finanziamento.flows.perfezionamento.nodes.contatto_diretto');
        if (! $node) {
            return;
        }

        foreach (DB::table('flows')->where('code', 'perfezionamento')->get() as $flow) {
            $email = DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'email')->first();
            if (! $email) {
                continue;
            }

            if (! DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'contatto_diretto')->exists()) {
                DB::table('flow_nodes')->where('flow_id', $flow->id)->where('sort_order', '>', $email->sort_order)->increment('sort_order');

                $id = DB::table('flow_nodes')->insertGetId([
                    'flow_id' => $flow->id, 'code' => 'contatto_diretto', 'type' => 'choice', 'label' => $node['label'], 'prompt' => $node['prompt'],
                    'sort_order' => $email->sort_order + 1, 'skippable' => false, 'save' => true, 'jump_by' => 'answer',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('flow_node_jumps')->insert(['flow_node_id' => $id, 'when_value' => '*', 'go_to' => $node['next'], 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

                $position = 0;
                foreach ($node['options'] as $code => $title) {
                    DB::table('flow_node_options')->insert(['flow_node_id' => $id, 'code' => (string) $code, 'title' => $title, 'sort_order' => ++$position, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // L'email porta al nuovo passo solo se andava ancora all'IBAN (se è stata cambiata a mano non si tocca).
            DB::table('flow_node_jumps')->where('flow_node_id', $email->id)->where('when_value', '*')->where('go_to', 'iban')
                ->update(['go_to' => 'contatto_diretto', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Non si torna indietro: l'albero si ripristina dalla configurazione.
    }
};
