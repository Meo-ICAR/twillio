<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I documenti di una pratica: nome e tipo sono copiati dal catalogo, così una modifica al catalogo non altera le pratiche aperte.
        Schema::create('pratica_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finanziamento_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('requirement', 20)->default('obbligatorio');
            // da_ricevere | ricevuto | ok | rejected | integrazione_richiesta
            $table->string('status', 30)->default('da_ricevere')->index();
            // Annotazioni di AI e operatore (autore, utente, testo, data): contengono dati personali, quindi cifrate.
            $table->text('annotations')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_request_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pratica_documents');
    }
};
