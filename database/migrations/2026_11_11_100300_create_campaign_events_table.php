<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('event');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['campaign_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
    }
};
