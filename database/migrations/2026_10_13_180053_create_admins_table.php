<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admins are passwordless too. They are created by another admin (never self-signup).
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->string('profile_image')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('onboarding_type')->nullable();
            $table->unsignedBigInteger('onboarding_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
