<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('type')->index();
            $table->unsignedBigInteger('source_id')->nullable()->after('source');
            $table->index(['client_id', 'source', 'created_at']);
        });

        // Backfill direct quick / bulk SMS first.
        DB::table('inboxes')
            ->whereNull('source')
            ->update([
                'source' => DB::raw("CASE WHEN type = 'quick' THEN 'quick_sms' ELSE 'bulk_sms' END"),
            ]);

        // Backfill promotion & occasion campaign SMS.
        if (Schema::hasTable('campaigns')) {
            DB::table('campaigns')
                ->whereNotNull('inbox_id')
                ->select(['id', 'type', 'inbox_id'])
                ->orderBy('id')
                ->chunkById(200, function ($campaigns) {
                    foreach ($campaigns as $campaign) {
                        $tag = $campaign->type === 'occasion' ? 'campaign_occasion' : 'campaign_promotion';

                        DB::table('inboxes')
                            ->where('id', $campaign->inbox_id)
                            ->update([
                                'source' => $tag,
                                'source_id' => $campaign->id,
                            ]);
                    }
                });
        }

        // Backfill survey SMS.
        if (Schema::hasTable('surveys')) {
            DB::table('surveys')
                ->whereNotNull('inbox_id')
                ->select(['id', 'inbox_id'])
                ->orderBy('id')
                ->chunkById(200, function ($surveys) {
                    foreach ($surveys as $survey) {
                        DB::table('inboxes')
                            ->where('id', $survey->inbox_id)
                            ->update([
                                'source' => 'survey',
                                'source_id' => $survey->id,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'source', 'created_at']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'source_id']);
        });
    }
};
