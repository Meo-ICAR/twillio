<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un percorso può avere una copia di prova con lo stesso codice: la produzione e la prova convivono.
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('is_active');
            $table->dropUnique(['code']);
            $table->unique(['code', 'is_test']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status');
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status')->index();
        });

        // Chi è associato a un utente vede le voci di prova nel menu WhatsApp.
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 30)->nullable()->after('email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('whatsapp_number'));
        Schema::table('loan_requests', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('flows', function (Blueprint $table) {
            $table->dropUnique(['code', 'is_test']);
            $table->unique('code');
            $table->dropColumn('is_test');
        });
    }
};
