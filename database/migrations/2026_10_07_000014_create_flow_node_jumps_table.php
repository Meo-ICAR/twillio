<?php

use App\Services\Flows\LegacyJumps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I salti: «quando la risposta (o il dato) vale X, vai alla domanda Y». «*» è l'uscita predefinita.
        Schema::create('flow_node_jumps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
            $table->string('when_value', 60)->comment('Valore che fa scattare il salto; * = qualsiasi altro');
            $table->string('go_to', 60)->comment('Codice della domanda di destinazione');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['flow_node_id', 'when_value']);
        });

        Schema::table('flow_nodes', function (Blueprint $table) {
            // Da cosa dipendono i salti: «answer» = dalla risposta a questa domanda; altrimenti il codice di una domanda già risposta (es. prodotto).
            $table->string('jump_by', 60)->default('answer')->after('save');
        });

        // I vecchi campi (next_to, next_map, params.next_by) diventano righe e campo, senza perdere le modifiche già fatte.
        foreach (DB::table('flow_nodes')->get() as $row) {
            $params = json_decode($row->params ?? 'null', true) ?? [];
            $position = 0;

            foreach (LegacyJumps::fromColumns($row->next_to, json_decode($row->next_map ?? 'null', true)) as $jump) {
                DB::table('flow_node_jumps')->insert([
                    'flow_node_id' => $row->id, 'when_value' => $jump['when'], 'go_to' => $jump['go_to'],
                    'sort_order' => ++$position, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $update = ['jump_by' => $params['next_by'] ?? 'answer'];
            if (array_key_exists('next_by', $params)) {
                unset($params['next_by']);
                $update['params'] = $params ? json_encode($params) : null;
            }
            DB::table('flow_nodes')->where('id', $row->id)->update($update);
        }

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropColumn(['next_to', 'next_map']);
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->string('next_to', 60)->nullable();
            $table->json('next_map')->nullable();
        });

        foreach (DB::table('flow_nodes')->get() as $row) {
            $jumps = DB::table('flow_node_jumps')->where('flow_node_id', $row->id)->orderBy('sort_order')->get();
            $params = json_decode($row->params ?? 'null', true) ?? [];
            if ($row->jump_by !== 'answer') {
                $params['next_by'] = $row->jump_by;
            }

            DB::table('flow_nodes')->where('id', $row->id)->update([
                'next_to' => $jumps->count() === 1 && $jumps[0]->when_value === '*' ? $jumps[0]->go_to : null,
                'next_map' => $jumps->isNotEmpty() && ! ($jumps->count() === 1 && $jumps[0]->when_value === '*')
                    ? json_encode($jumps->pluck('go_to', 'when_value')->all()) : null,
                'params' => $params ? json_encode($params) : null,
            ]);
        }

        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropColumn('jump_by');
        });
        Schema::dropIfExists('flow_node_jumps');
    }
};
