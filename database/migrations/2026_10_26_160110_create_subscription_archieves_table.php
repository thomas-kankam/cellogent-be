<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of a subscription after it is paid/replaced.
     * Table name matches the product spelling: subscription_archieves.
     */
    public function up(): void
    {
        Schema::create('subscription_archieves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('plan_name')->nullable();
            $table->decimal('plan_price', 12, 2)->nullable();
            $table->string('plan_type')->nullable(); // custom, non_expiry, monthly
            $table->unsignedInteger('duration_in_months')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('payment_status')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_archieves');
    }
};
