<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('wa_number')->index();
            $table->string('flow');
            $table->string('node');
            $table->foreignId('loan_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('data')->nullable();
            $table->json('history')->nullable();
            $table->string('status')->default('attiva')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
