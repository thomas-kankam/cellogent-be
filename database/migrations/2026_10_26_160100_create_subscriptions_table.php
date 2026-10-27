<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('plan_name')->nullable(); // Gold, Silver, Bronze, Platinum
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('units')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('type')->default('monthly')->index(); // custom, non_expiry, monthly
            $table->unsignedInteger('duration_in_months')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
