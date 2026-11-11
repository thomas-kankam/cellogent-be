<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_events', function (Blueprint $table) {
            // Set only on yearly occasion "sent" events. Unique so a phone is never messaged twice
            // for the same campaign in the same year (NULL years, i.e. every other event, are unrestricted).
            $table->unsignedSmallInteger('occasion_year')->nullable()->after('event');
            $table->unique(['campaign_id', 'occasion_year', 'phone'], 'campaign_events_occasion_unique');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_events', function (Blueprint $table) {
            $table->dropUnique('campaign_events_occasion_unique');
            $table->dropColumn('occasion_year');
        });
    }
};
