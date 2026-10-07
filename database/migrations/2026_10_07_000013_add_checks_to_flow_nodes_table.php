<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            // Controlli agganciati alla risposta: nomi del registro, o {"name": "...", ...parametri}.
            $table->json('checks')->nullable()->after('params');
        });

        // I vecchi parametri (derive, checksum, min_age) diventano controlli, senza perdere le modifiche fatte dal pannello.
        foreach (DB::table('flow_nodes')->whereNotNull('params')->get() as $row) {
            $params = json_decode($row->params, true) ?? [];
            $checks = [];

            if (($params['derive'] ?? null) === 'codice_fiscale') {
                $checks[] = 'codice_fiscale';
                $checks[] = [
                    'name' => 'maggiorenne', 'campo' => 'data_nascita', 'anni' => $params['min_age'] ?? 18,
                    'messaggio' => $params['age_error'] ?? 'Dal codice fiscale il cliente risulta minorenne: controlla il codice.',
                ];
            }
            if (($params['checksum'] ?? null) === 'iban') {
                $checks[] = 'iban';
            }

            if ($checks) {
                $rest = array_diff_key($params, array_flip(['derive', 'derives', 'min_age', 'age_error', 'checksum']));
                DB::table('flow_nodes')->where('id', $row->id)->update([
                    'checks' => json_encode($checks),
                    'params' => $rest ? json_encode($rest) : null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropColumn('checks');
        });
    }
};
