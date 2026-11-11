<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('name');
            $table->text('intro')->nullable();
            $table->text('body')->nullable();
            $table->json('contact_group_ids');
            $table->string('sender')->nullable();
            $table->timestamp('send_at')->nullable();
            $table->string('public_token', 64)->unique();
            $table->boolean('reward_enabled')->default(false);
            $table->json('reward')->nullable();
            $table->unsignedBigInteger('reward_id')->nullable()->index();
            $table->string('funding_method')->nullable();
            $table->unsignedBigInteger('wallet_hold_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->decimal('reserved_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->unsignedInteger('eligible_recipients')->default(0);
            $table->unsignedInteger('not_eligible')->default(0);
            $table->json('analytics')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
