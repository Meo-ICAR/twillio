<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prodotti generici per ogni settore: `params` contiene i valori propri del settore (es. prezzi di un'offerta energia).
        Schema::table('products', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('type');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('params')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['code', 'description', 'is_active', 'params']));
    }
};
