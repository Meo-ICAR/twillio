<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Quando il file è stato consegnato al CRM: serve a non rimandarlo se l'invio dei documenti viene ripetuto.
            $table->timestamp('crm_sent_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $t) => $t->dropColumn('crm_sent_at'));
    }
};
