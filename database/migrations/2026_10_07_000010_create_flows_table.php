<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I percorsi di conversazione (richiesta, perfezionamento, documenti).
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            // Messaggio mostrato una sola volta, all'inizio del dialogo. Vuoto = nessuna intestazione.
            $table->text('header')->nullable();
            $table->string('start', 60);
            $table->string('restart', 60);
            // Etichette per il riepilogo di dati che non hanno una domanda propria (es. dati ricavati dal codice fiscale).
            $table->json('labels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
