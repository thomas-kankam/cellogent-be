<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('name');
            $table->string('type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('occasion_type')->nullable();
            $table->date('custom_date')->nullable();
            $table->string('trigger_offset')->nullable();
            $table->unsignedInteger('custom_offset_days')->nullable();
            $table->string('send_time')->nullable();
            $table->boolean('repeat_yearly')->default(false);
            $table->json('contact_group_ids');
            $table->string('sender');
            $table->text('body');
            $table->timestamp('send_at')->nullable();
            $table->json('cta')->nullable();
            $table->boolean('reward_enabled')->default(false);
            $table->json('reward')->nullable();
            $table->unsignedBigInteger('reward_id')->nullable()->index();
            $table->string('funding_method')->nullable();
            $table->unsignedBigInteger('wallet_hold_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->decimal('reserved_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->unsignedBigInteger('estimated_cost_units')->default(0);
            $table->unsignedInteger('eligible_recipients')->default(0);
            $table->unsignedInteger('not_eligible')->default(0);
            $table->json('analytics')->nullable();
            $table->unsignedBigInteger('inbox_id')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
