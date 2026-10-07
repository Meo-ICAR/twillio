<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dati della pratica letti dall'AI dai documenti: sono proposte finché l'agente non le conferma.
        Schema::create('pratica_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_request_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            // Dato personale, quindi cifrato.
            $table->text('value');
            // proposto | confermato | rifiutato
            $table->string('status', 20)->default('proposto')->index();
            // Documento da cui viene (codice del documento della pratica) e allegato letto.
            $table->string('source_code', 40)->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_request_id', 'key']);
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            // L'informativa è stata controllata (nostro modulo, firmato) dall'AI o approvata da un operatore: da qui si possono leggere i documenti.
            $table->timestamp('privacy_verified_at')->nullable()->after('privacy_received_at');
        });

        Schema::table('attachments', function (Blueprint $table) {
            // Controlli da eseguire quando l'analisi, rimandata in attesa dell'informativa verificata, potrà partire.
            $table->json('pending_checks')->nullable()->after('analysis');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('pending_checks');
        });
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropColumn('privacy_verified_at');
        });
        Schema::dropIfExists('pratica_fields');
    }
};
