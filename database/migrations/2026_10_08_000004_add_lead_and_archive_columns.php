<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tipo_rapporto del preventivatore → `tipologia` del servizio di caricamento lead. */
    private const LEAD = [
        'Pubblico' => 'Pubblico', 'Privato Altra forma' => 'Privato altra forma', 'Privato SPA' => 'Privato',
        'Privato Small Business' => 'Privato small business', 'Pensionato INPS' => 'Pensionato INPS',
        'Pensionato INPDAP' => 'Pensionato altri enti', 'Pensionato altri enti' => 'Pensionato altri enti',
    ];

    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->text('istruttoria_passkey')->nullable()->after('url_istruttoria'));

        Schema::table('loan_requests', function (Blueprint $t) {
            $t->string('crm_lead_id')->nullable();
            $t->timestamp('documents_archived_at')->nullable();
        });

        Schema::table('quote_employment_map', fn (Blueprint $t) => $t->string('lead_tipologia')->nullable()->after('tipo_rapporto'));
        foreach (self::LEAD as $rapporto => $lead) {
            DB::table('quote_employment_map')->where('tipo_rapporto', $rapporto)->update(['lead_tipologia' => $lead]);
        }
    }

    public function down(): void
    {
        Schema::table('quote_employment_map', fn (Blueprint $t) => $t->dropColumn('lead_tipologia'));
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn(['crm_lead_id', 'documents_archived_at']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('istruttoria_passkey'));
    }
};
