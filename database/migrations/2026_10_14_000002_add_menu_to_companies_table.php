<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Voci del menu WhatsApp proprie della company (id => titolo); vuoto = menu predefinito.
        Schema::table('companies', fn (Blueprint $t) => $t->json('menu')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('menu'));
    }
};
