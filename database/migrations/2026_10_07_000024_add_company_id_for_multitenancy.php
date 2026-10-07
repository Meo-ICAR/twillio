<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Predisposizione al multitenant, senza attivarlo: ogni tabella indicata riceve la company a cui appartiene.
     * Fornitori ha già un `company_id` (UUID dell'anagrafica madre): lì la colonna si chiama `tenant_company_id`.
     * I check (flow_checks) sono condivisi e non hanno la company. I dati esistenti vanno alla company attuale.
     */
    private const TABLES = [
        'users' => 'company_id',
        'fornitoris' => 'tenant_company_id',
        'loan_requests' => 'company_id',
        'attachments' => 'company_id',
        'conversations' => 'company_id',
        'flows' => 'company_id',
        'flow_nodes' => 'company_id',
        'flow_node_options' => 'company_id',
        'flow_node_jumps' => 'company_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->foreignId($column)->nullable()->constrained('companies')->nullOnDelete();
            });
        }

        $company = DB::table('companies')->orderBy('id')->value('id');
        if ($company) {
            foreach (self::TABLES as $table => $column) {
                DB::table($table)->whereNull($column)->update([$column => $company]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropConstrainedForeignId($column);
                });
            }
        }
    }
};
