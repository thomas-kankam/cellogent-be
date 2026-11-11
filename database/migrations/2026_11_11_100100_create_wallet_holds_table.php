<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('wallet_id')->index();
            $table->string('holdable_type');
            $table->unsignedBigInteger('holdable_id');
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('status')->default('active');
            $table->string('purpose')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            // No FK cascades — keep holds for audit even if related rows go away.
            $table->index(['client_id', 'status']);
            $table->index(['holdable_type', 'holdable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
    }
};
