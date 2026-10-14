<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Le tabelle indicate hanno `company_id` facoltativo: i record creati finora senza company vanno alla company attuale. */
    private const TABLES = ['loan_requests', 'attachments', 'conversations', 'flows', 'flow_nodes', 'flow_node_options', 'flow_node_jumps', 'users'];

    public function up(): void
    {
        $company = DB::table('companies')->orderBy('id')->value('id');
        if (! $company) {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $company]);
        }
    }

    public function down(): void
    {
        // Irreversibile: non si sa quali record erano senza company.
    }
};
