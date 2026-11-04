<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temporary work table. A row lives here while its inbox is being sent,
     * then it is removed. The dispatcher re-queues rows stuck in "pending".
     */
    public function up(): void
    {
        Schema::create('outboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inbox_id')->unique();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('request_type', 16);
            $table->string('type', 16);
            $table->text('to')->nullable();
            $table->string('from', 20);
            $table->text('message');
            $table->json('contact_group_ids')->nullable();
            $table->unsignedBigInteger('units_used')->default(0);
            $table->unsignedBigInteger('previous_units')->nullable();
            $table->string('aggregator', 32)->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('message_id', 64)->nullable();
            $table->string('status', 16)->default('pending'); // pending, processing
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outboxes');
    }
};
