<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La sigla produttore vive sulla tabella condivisa `fornitoris` (unico-core).
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('unico-core.connection', config('database.default'));

        if (! Schema::connection($connection)->hasTable('fornitoris')) {
            return;
        }

        if (Schema::connection($connection)->hasColumn('fornitoris', 'sigla')) {
            return;
        }

        Schema::connection($connection)->table('fornitoris', function (Blueprint $table) {
            $table->string('sigla', 10)->nullable()->index();
        });
    }

    public function down(): void
    {
        $connection = config('unico-core.connection', config('database.default'));

        if (! Schema::connection($connection)->hasTable('fornitoris')
            || ! Schema::connection($connection)->hasColumn('fornitoris', 'sigla')) {
            return;
        }

        Schema::connection($connection)->table('fornitoris', function (Blueprint $table) {
            $table->dropColumn('sigla');
        });
    }
};
