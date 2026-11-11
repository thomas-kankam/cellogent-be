<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('service_type')->index();
            $table->string('provider_code')->nullable();
            $table->string('network')->nullable();
            $table->string('beneficiary');
            $table->decimal('size_gb', 12, 2)->nullable();
            $table->string('package_code')->nullable();
            $table->decimal('face_amount', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('reference')->nullable()->unique();
            $table->string('provider_reference')->nullable();
            $table->string('status')->default('pending');
            $table->string('funding_method');
            $table->json('provider_payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('utility_meta')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_orders');
    }
};
