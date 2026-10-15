<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->text('cadastre')->nullable()->after('personal');
        });
    }

    public function down(): void
    {
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropColumn('cadastre');
        });
    }
};
