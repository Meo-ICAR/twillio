<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // ricevuto | verificato | difforme | non_leggibile | non_analizzato
            $table->string('status')->default('ricevuto')->after('mime');
            // Dati letti dal documento e difformità: contengono dati personali, quindi cifrati.
            $table->text('analysis')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['status', 'analysis']);
        });
    }
};
