<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('agent_wa_number')->index();
            $table->string('product');
            $table->string('status')->default('richiesta');
            $table->json('answers');
            $table->text('personal')->nullable();
            $table->timestamp('privacy_received_at')->nullable();
            $table->timestamp('perfected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_requests');
    }
};
