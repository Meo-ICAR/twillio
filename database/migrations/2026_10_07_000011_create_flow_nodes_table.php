<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le domande (nodi) di un percorso.
        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            // choice | text | code | file | summary | check
            $table->string('type', 20);
            $table->string('label')->nullable()->comment('Etichetta nel riepilogo');
            $table->text('prompt');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // La risposta si può saltare scrivendo «salta». Vale solo se la domanda ha un'uscita predefinita (next_to o '*' in next_map).
            $table->boolean('skippable')->default(false);
            $table->boolean('save')->default(true)->comment('Se la risposta entra nei dati della pratica');
            $table->string('next_to', 60)->nullable()->comment('Domanda successiva, se è sempre la stessa');
            $table->json('next_map')->nullable()->comment('Domanda successiva per risposta (o per prodotto): {"si":"rata","*":"crif"}');
            // Comportamenti con nome (regole, ricava dati, controlli, ...): vedi config/finanziamento.php.
            $table->json('params')->nullable();
            $table->timestamps();

            $table->unique(['flow_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_nodes');
    }
};
