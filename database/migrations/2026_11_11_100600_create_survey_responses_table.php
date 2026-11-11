<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survey_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('phone')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->json('answers')->nullable();
            $table->string('reward_status')->nullable();
            $table->json('reward_meta')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
