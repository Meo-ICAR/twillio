<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le opzioni di una domanda a scelta.
        Schema::create('flow_node_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title', 100)->comment('Titolo mostrato su WhatsApp: max 24 caratteri');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['flow_node_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_node_options');
    }
};
