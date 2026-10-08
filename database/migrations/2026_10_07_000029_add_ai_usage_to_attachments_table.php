<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Consumo dell'AI per leggere questo documento. Il costo è in dollari (la valuta con cui fattura il fornitore).
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('ai_input_tokens')->nullable();
            $table->unsignedInteger('ai_output_tokens')->nullable();
            $table->decimal('ai_cost', 10, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $t) => $t->dropColumn(['ai_model', 'ai_input_tokens', 'ai_output_tokens', 'ai_cost']));
    }
};
