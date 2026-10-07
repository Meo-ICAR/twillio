<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'elenco dei controlli disponibili per le domande. Nome e descrizione li dà la classe.
        Schema::create('flow_checks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique()->comment('Nome con cui le domande agganciano il controllo');
            $table->string('class')->comment('Classe che implementa App\\Services\\Checks\\NodeCheck');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // I controlli già registrati in configurazione restano disponibili senza altri passaggi.
        $order = 0;
        foreach (config('finanziamento.checks', []) as $code => $class) {
            DB::table('flow_checks')->insert([
                'code' => $code, 'class' => $class, 'is_active' => true, 'sort_order' => ++$order,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_checks');
    }
};
