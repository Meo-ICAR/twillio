<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Driver del CRM dell'istruttoria: mediafacile, generic, unicoloan... o «email» (nessun CRM). Nullo = come prima:
            // Mediafacile se c'è url_istruttoria, altrimenti email.
            $table->string('crm_driver', 30)->nullable()->after('istruttoria_passkey');
            // Configurazione del driver (indirizzo, credenziali, modello del messaggio): cifrata.
            $table->text('crm_config')->nullable()->after('crm_driver');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['crm_driver', 'crm_config']));
    }
};
