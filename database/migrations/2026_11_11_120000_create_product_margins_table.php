<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_margins', function (Blueprint $table) {
            $table->id();
            // data_bundle | airtime | ecg | tv | results | sms_units | wallet_top_up | mashup
            $table->string('product_type', 32)->index();
            $table->string('network', 60)->nullable();
            $table->string('package_id', 120)->nullable();
            $table->string('markup_type', 16)->default('percent'); // percent | fixed
            $table->decimal('markup_value', 12, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            // No foreign keys: margin history must never be wiped by another table's deletes.
            $table->index(['product_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_margins');
    }
};
