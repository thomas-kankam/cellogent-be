<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('contact_group_id')->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('gender')->nullable();
            $table->string('phone_number'); // required — SMS is sent to this number
            $table->string('date_of_birth')->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('gps_address')->nullable();
            $table->string('address')->nullable();
            $table->string('dnd_status')->default('inactive')->index(); // inactive = can receive SMS
            $table->timestamps();

            $table->unique(['contact_group_id', 'phone_number'], 'contacts_group_phone_unique');
            $table->unique(['contact_group_id', 'email'], 'contacts_group_email_unique');
            $table->unique(['contact_group_id', 'date_of_birth'], 'contacts_group_dob_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
