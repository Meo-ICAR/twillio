<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Recapiti a cui mandare chi non è un produttore convenzionato (segnalatore occasionale).
            $table->string('customer_care_phone', 40)->nullable()->after('email');
            $table->string('customer_care_email')->nullable()->after('customer_care_phone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['customer_care_phone', 'customer_care_email']);
        });
    }
};
