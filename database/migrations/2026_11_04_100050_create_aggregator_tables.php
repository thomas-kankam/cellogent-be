<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-only aggregator data: balance history and a compact log of every call we make.
     */
    public function up(): void
    {
        Schema::create('aggregator_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('aggregator', 32);
            $table->bigInteger('credit');
            $table->bigInteger('intl_credit')->default(0);
            $table->unsignedBigInteger('client_units_outstanding')->default(0);
            $table->bigInteger('headroom');
            $table->boolean('is_low')->default(false);
            $table->boolean('alert_sent')->default(false);
            $table->timestamps();

            $table->index(['aggregator', 'created_at']);
        });

        Schema::create('aggregator_requests', function (Blueprint $table) {
            $table->id();
            $table->string('aggregator', 32);
            $table->string('endpoint', 32); // quick, bulk, resend, dlr, balance
            $table->unsignedBigInteger('inbox_id')->nullable()->index();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('ok')->default(false);
            $table->string('status_message')->nullable();
            $table->string('transaction_id', 64)->nullable()->index();
            $table->string('sequence_no', 64)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('response')->nullable(); // full body only for errors
            $table->timestamps();

            $table->index(['endpoint', 'created_at']);
            $table->index(['ok', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregator_requests');
        Schema::dropIfExists('aggregator_balance_snapshots');
    }
};
