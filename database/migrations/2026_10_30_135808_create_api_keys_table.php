<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('prefix', 16)->index();
            $table->string('token'); // hashed. plaintext is returned only on create/regenerate
            $table->string('status')->default('active')->index(); // one active key per client; regenerate sets old ones inactive

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
