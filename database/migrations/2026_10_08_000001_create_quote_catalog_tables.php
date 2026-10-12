<?php

use Database\Seeders\QuoteCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Liste valori del servizio di simulazione Mediafacile (specifica 3.8), modificabili quando arriva il tracciato definitivo. */
    public function up(): void
    {
        Schema::create('quote_contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('value')->unique();
            $table->string('label');
            $table->string('product')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('quote_employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('value')->unique();
            $table->json('contracts')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_durations', function (Blueprint $table) {
            $table->id();
            $table->string('contract');
            $table->unsignedSmallInteger('months');
            $table->timestamps();
            $table->unique(['contract', 'months']);
        });

        Schema::create('quote_employment_map', function (Blueprint $table) {
            $table->id();
            $table->string('lavoro');
            $table->string('ente_pensione')->nullable();
            $table->string('dimensione_azienda')->nullable();
            $table->string('tipo_rapporto');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_band_bounds', function (Blueprint $table) {
            $table->id();
            $table->string('dimension');
            $table->string('code');
            $table->string('label')->nullable();
            $table->unsignedInteger('low');
            $table->unsignedInteger('high');
            $table->timestamps();
            $table->unique(['dimension', 'code']);
        });

        Schema::create('quote_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_request_id')->constrained()->cascadeOnDelete();
            $table->string('scenario');
            $table->json('request');
            $table->longText('response')->nullable();
            $table->unsignedSmallInteger('offers_count')->default(0);
            $table->decimal('erogato_min', 12, 2)->nullable();
            $table->decimal('erogato_max', 12, 2)->nullable();
            $table->timestamps();
        });

        (new QuoteCatalogSeeder)->run();
    }

    public function down(): void
    {
        foreach (['quote_simulations', 'quote_band_bounds', 'quote_employment_map', 'quote_durations', 'quote_employment_types', 'quote_contract_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
