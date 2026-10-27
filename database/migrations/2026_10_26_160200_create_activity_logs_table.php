<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for every product action (OTP, onboarding, sender ID, etc.).
     *
     * transmitter = who did it
     * receiver    = who it was done to
     * metadata    = extra facts (rejection reason, sender_id value, ...)
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('module')->index();
            $table->string('action')->index();
            $table->string('actor')->index(); // admin | client | system
            $table->nullableMorphs('transmitter');
            $table->nullableMorphs('receiver');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
