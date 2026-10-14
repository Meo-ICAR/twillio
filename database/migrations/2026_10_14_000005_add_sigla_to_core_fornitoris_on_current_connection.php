<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sigla produttore vive su `fornitoris` della connessione unico-core. La migrazione del 12/10 girò quando quella connessione
 * puntava ancora al DB locale: ora che punta al DB condiviso la colonna mancava. Idempotente, come la precedente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('unico-core.connection', config('database.default'));

        if (! Schema::connection($connection)->hasTable('fornitoris') || Schema::connection($connection)->hasColumn('fornitoris', 'sigla')) {
            return;
        }

        Schema::connection($connection)->table('fornitoris', function (Blueprint $table) {
            $table->string('sigla', 10)->nullable()->index();
        });
    }

    public function down(): void
    {
        // Non si toglie: la colonna è condivisa e può contenere sigle già usate nei codici pratica.
    }
};
