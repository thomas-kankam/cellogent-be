<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every unit movement on a client's subscription (debit on send, refund on failure).
     */
    public function up(): void
    {
        Schema::create('sms_unit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('inbox_id')->nullable()->index();
            $table->string('type', 16); // debit, refund
            $table->unsignedBigInteger('units');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_unit_ledgers');
    }
};
