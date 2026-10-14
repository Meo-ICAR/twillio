<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            // Il codice del percorso è unico per company e variante (produzione/prova).
            $table->dropUnique('flows_code_is_test_unique');
            $table->unique(['company_id', 'code', 'is_test'], 'flows_company_code_is_test_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropUnique('flows_company_code_is_test_unique');
            $table->unique(['code', 'is_test'], 'flows_code_is_test_unique');
        });
    }
};
