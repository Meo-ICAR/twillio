<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Casella dell'istruttoria che riceve la pratica con i suoi allegati.
            $table->string('istruttoria_email')->nullable()->after('customer_care_email');
        });
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('perfected_at');
        });
    }

    public function down(): void
    {
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn('emailed_at'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('istruttoria_email'));
    }
};
