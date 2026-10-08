<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Passkey del servizio di simulazione Mediafacile, usata con l'URL del preventivatore.
            $table->text('preventivatore_passkey')->nullable()->after('url_preventivatore');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('preventivatore_passkey'));
    }
};
