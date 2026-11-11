<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->index();
            $table->string('size')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('network')->nullable();
            $table->unsignedBigInteger('units')->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->string('provider_code')->nullable();
            $table->string('bundle_id')->nullable();
            $table->string('status')->default('instock');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
