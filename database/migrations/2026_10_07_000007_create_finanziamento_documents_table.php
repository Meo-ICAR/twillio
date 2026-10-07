<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogo: per ogni tipo di finanziamento, i documenti da caricare.
        Schema::create('finanziamento_documents', function (Blueprint $table) {
            $table->id();
            $table->string('product', 30);
            $table->string('code', 40);
            $table->string('name', 100)->comment('Titolo mostrato su WhatsApp: max 24 caratteri');
            $table->text('description')->nullable();
            // obbligatorio | facoltativo | integrativo (richiesto dall'istruttore per un approfondimento)
            $table->string('requirement', 20)->default('obbligatorio');
            // Se valorizzato l'AI legge il documento: identita | codice_fiscale | reddito
            $table->string('ai_kind', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finanziamento_documents');
    }
};
