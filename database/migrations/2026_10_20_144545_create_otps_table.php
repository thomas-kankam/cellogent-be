<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per issued OTP. `token` is a HMAC, not the 6-digit code.
     * `actor` is the guard name: client | admin.
     */
    public function up(): void
    {
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->string('email')->index();
            $table->string('actor')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('type')->nullable()->index(); // login | registration
            $table->string('channel')->nullable()->index(); // email | sms
            $table->dateTime('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
