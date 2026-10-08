<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornitoris', function (Blueprint $table) {
            // Sigla del produttore: apre il codice dei suoi preventivi (<sigla>-MMGG-HHmm). Se manca la ricava il programma.
            $table->string('sigla', 10)->nullable()->index();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('type', 20)->default('FINANCE')->after('name');
            $table->boolean('is_trial')->default(false);
            $table->date('trialend_at')->nullable();
            $table->date('trial_activated_at')->nullable();
            $table->date('activated_at')->nullable()->comment('Attivazione del contratto');
            $table->string('whatsapp_number', 30)->nullable();
            $table->string('logo')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('company_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id']);
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            // I documenti si possono chiedere anche direttamente al cliente; recapiti del cliente (dati personali: cifrati).
            $table->boolean('direct_contact')->default(false);
            $table->text('customer_phone')->nullable();
            $table->text('customer_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn(['direct_contact', 'customer_phone', 'customer_email']));
        Schema::dropIfExists('company_products');
        Schema::dropIfExists('products');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['type', 'is_trial', 'trialend_at', 'trial_activated_at', 'activated_at', 'whatsapp_number', 'logo']));
        Schema::table('fornitoris', fn (Blueprint $t) => $t->dropColumn('sigla'));
    }
};
