<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('produce_documents')->default(false)->comment('Produce e fa firmare i moduli con unico-core al perfezionamento');
            $table->unsignedBigInteger('core_company_id')->nullable()->comment('Id della company nel DB core');
            $table->string('core_tipo_prodotto', 60)->nullable()->comment('Tipo di pratica del core per scegliere i task (es. Utenza)');
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->string('signature_status', 20)->nullable()->index();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('documents_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn(['signature_status', 'signed_at', 'documents_sent_at']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['produce_documents', 'core_company_id', 'core_tipo_prodotto']));
    }
};
