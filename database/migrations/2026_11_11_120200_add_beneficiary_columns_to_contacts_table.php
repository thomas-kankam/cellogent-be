<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // unknown | verified | unverified | submitted | invalid
            $table->string('beneficiary_status', 16)->default('unknown')->after('dnd_status')->index();
            $table->timestamp('beneficiary_checked_at')->nullable()->after('beneficiary_status');
            $table->json('beneficiary_meta')->nullable()->after('beneficiary_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['beneficiary_status', 'beneficiary_checked_at', 'beneficiary_meta']);
        });
    }
};
