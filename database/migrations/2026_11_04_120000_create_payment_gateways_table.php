<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-controlled gateway order. Lowest priority number is tried first;
     * the next enabled gateway is the fallback.
     */
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // kairos, paystack
            $table->string('name', 64);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(1);
            $table->json('methods');              // momo, card
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'priority']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable(); // JSON encoded
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('wallet_id');
            $table->string('type', 16);            // credit, debit
            $table->string('purpose', 32);         // wallet_top_up, sms_units, refund, adjustment
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('payment_gateways');
    }
};
