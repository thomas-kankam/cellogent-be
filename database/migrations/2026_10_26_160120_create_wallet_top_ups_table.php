<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id')->index();
            $table->unsignedBigInteger('client_id')->index(); // many top-ups per client
            $table->decimal('amount', 12, 2);                  // credited to the wallet
            $table->decimal('charge', 12, 2)->default(0);      // our charge on top
            $table->decimal('amount_paid', 12, 2)->default(0); // amount + charge
            $table->decimal('previous_balance', 12, 2)->nullable();
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
