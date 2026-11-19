<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('global')->index(); // global (admin) | client
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('type', 32)->index(); // quick_sms | bulk_sms | campaign_promotion | campaign_occasion | survey
            $table->string('occasion_type', 32)->nullable()->index(); // birthday | anniversary | holiday | custom
            $table->string('category', 60)->nullable()->index();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->text('intro')->nullable();
            $table->text('body');
            $table->json('cta')->nullable();
            $table->json('questions')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['scope', 'type', 'status']);
            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
