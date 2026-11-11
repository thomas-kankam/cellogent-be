<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_disbursements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('source_type', 16); // campaign | survey
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('phone', 20);
            // 'once' for one-off rewards; the year (e.g. 2026) for yearly birthday campaigns.
            $table->string('period', 16)->default('once');
            $table->string('reward_type', 16); // airtime | data | money
            $table->decimal('amount', 12, 2); // what the client funds for this recipient
            $table->string('network', 40)->nullable();
            $table->string('package_id', 120)->nullable();
            $table->string('status', 16)->default('pending'); // pending | processing | paid | failed
            $table->string('provider', 16)->nullable(); // swift | paystack
            $table->string('reference', 64)->unique(); // our idempotency key with the provider
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedBigInteger('utility_order_id')->nullable()->index();
            $table->unsignedBigInteger('wallet_hold_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // No foreign keys or cascades: payouts stay on record even if a campaign is deleted.
            $table->unique(['source_type', 'source_id', 'phone', 'period'], 'reward_disbursements_once_unique');
            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_disbursements');
    }
};
