<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per recipient. Message text is copied from the inbox onto each
     * sent row so recipient history is self-contained for quick and bulk.
     */
    public function up(): void
    {
        Schema::create('sents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inbox_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('request_type', 16);
            $table->string('type', 16);
            $table->string('to', 20);
            $table->string('from', 20);
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('units_used')->default(1);
            $table->string('aggregator', 32)->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('message_id', 64)->nullable();     // aggregator transactionId, used for resend
            $table->string('sequence_no', 64)->nullable();    // aggregator id used for delivery receipts
            $table->string('job_id', 64)->nullable();         // aggregator batch id
            $table->string('status', 16)->default('queued');  // queued, submitted, delivered, failed
            $table->string('dlr_status', 20)->nullable();     // raw aggregator delivery status
            $table->string('error')->nullable();
            $table->boolean('units_refunded')->default(false);
            $table->unsignedBigInteger('retry_inbox_id')->nullable()->index();
            $table->unsignedTinyInteger('dlr_attempts')->default(0);
            $table->timestamp('next_dlr_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['inbox_id', 'to']);
            $table->index(['inbox_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index(['status', 'next_dlr_at']);
            $table->index('sequence_no');
            $table->index('message_id');
            $table->index('to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sents');
    }
};
