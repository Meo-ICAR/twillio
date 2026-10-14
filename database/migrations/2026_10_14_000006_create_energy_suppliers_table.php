<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Venditori di energia elettrica e gas (elenco ARERA, importato da database/data/arera-venditori.csv).
        Schema::create('energy_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->index()->comment('Nome normalizzato per il confronto con quanto scrive l\'agente');
            $table->string('vat', 30)->unique()->comment('Partita IVA (o identificativo fiscale estero)');
            $table->unsignedInteger('arera_id')->nullable();
            $table->boolean('sells_electricity')->default(false);
            $table->boolean('sells_gas')->default(false);
            $table->string('website')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_suppliers');
    }
};
