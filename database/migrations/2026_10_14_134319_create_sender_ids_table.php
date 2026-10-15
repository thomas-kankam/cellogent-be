<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sender_ids', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->string('sender_id');
            $table->string('status');
            $table->string('approved_by');
            $table->timestamp('approved_at');
            $table->string('rejected_by');
            $table->timestamp('rejected_at');
            $table->string('rejected_reason');
            $table->timestamp('rejected_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sender_ids');
    }
};
