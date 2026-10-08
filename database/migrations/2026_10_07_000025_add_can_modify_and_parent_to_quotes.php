<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Domande che l'agente può cambiare quando modifica un preventivo (importo e durata, nella configurazione di partenza). */
    private const MODIFIABLE = ['importo', 'durata'];

    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            // La domanda si richiede anche quando si modifica un preventivo già fatto.
            $table->boolean('can_modify')->default(false)->after('skippable');
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            // Il preventivo da cui questo è stato ricavato con «Modifica».
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('loan_requests')->nullOnDelete();
        });

        $flows = DB::table('flows')->where('code', 'richiesta')->pluck('id');
        DB::table('flow_nodes')->whereIn('flow_id', $flows)->whereIn('code', self::MODIFIABLE)->where('type', 'choice')->update(['can_modify' => true]);
    }

    public function down(): void
    {
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn('can_modify'));
    }
};
