<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_id_blacklists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('normalized', 50)->unique();          // lowercase letters + digits only
            $table->string('match_type', 16)->default('exact');  // exact, contains
            $table->string('source', 32)->nullable();            // aggregator, telco, internal
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('match_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_id_blacklists');
    }
};
