<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Il tipo di documento letto dall'AI (carta, patente, passaporto) rende inutile la domanda sul tipo:
     * si aggiunge il salto agli alberi già importati, senza toccare un salto già impostato a mano. Ripetibile.
     */
    public function up(): void
    {
        foreach (DB::table('flows')->where('code', 'perfezionamento')->pluck('id') as $flowId) {
            $node = DB::table('flow_nodes')->where('flow_id', $flowId)->where('code', 'documento_tipo')->first();
            if (! $node) {
                continue;
            }

            $params = json_decode((string) $node->params, true) ?: [];
            if (! array_key_exists('skip_if', $params)) {
                DB::table('flow_nodes')->where('id', $node->id)->update(['params' => json_encode($params + ['skip_if' => 'filled:documento_tipo']), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Non si torna indietro: l'albero si ripristina dalla configurazione.
    }
};
