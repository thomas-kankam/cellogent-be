<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('network')->nullable();
            $table->string('method')->nullable();
            $table->string('reference_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->string('purpose')->nullable()->index();
            $table->string('payment_link')->nullable();
            $table->decimal('amount', 12, 2)->default(0);        // value the client gets (top-up / plan price)
            $table->decimal('charge', 12, 2)->default(0);        // our charge on top (profit)
            $table->decimal('total_amount', 12, 2)->default(0);  // what the client is asked to pay
            $table->string('currency', 3)->default('GHS');
            $table->string('gateway', 32)->nullable()->index();  // kairos, paystack, wallet
            $table->string('gateway_reference', 100)->nullable()->index();
            $table->decimal('gateway_charge', 12, 2)->nullable(); // fee the gateway reports
            $table->string('msisdn', 20)->nullable();
            $table->string('email')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();        // admin only
            $table->unsignedSmallInteger('verify_attempts')->default(0);
            $table->timestamp('next_verify_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['status', 'next_verify_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
