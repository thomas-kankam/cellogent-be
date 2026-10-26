<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string("token", 6)->unique()->index();
            $table->string("actor")->nullable()->index();
            $table->integer("actor_id")->nullable();
            $table->string("type")->nullable()->index();
            $table->string("channel")->nullable()->index();
            $table->dateTime("expires_at")->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
