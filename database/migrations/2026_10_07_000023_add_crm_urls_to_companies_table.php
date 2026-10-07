<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // CRM del committente per le due fasi; vuoti = si invia una email all'istruttoria.
            $table->string('url_preventivatore')->nullable()->after('istruttoria_email');
            $table->string('url_istruttoria')->nullable()->after('url_preventivatore');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['url_preventivatore', 'url_istruttoria']));
    }
};
