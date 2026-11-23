<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inboxes', 'source')) {
            Schema::table('inboxes', function (Blueprint $table) {
                $table->string('source', 32)->nullable()->after('type')->index();
                $table->unsignedBigInteger('source_id')->nullable()->after('source');
                $table->index(['client_id', 'source', 'created_at']);
            });
        } elseif (! Schema::hasColumn('inboxes', 'source_id')) {
            Schema::table('inboxes', function (Blueprint $table) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source');
            });
        }

        // Backfill direct quick / bulk SMS first.
        DB::table('inboxes')
            ->whereNull('source')
            ->update([
                'source' => DB::raw("CASE WHEN type = 'quick' THEN 'quick_sms' ELSE 'bulk_sms' END"),
            ]);

        // Backfill promotion & occasion campaign SMS.
        if (Schema::hasTable('campaigns')) {
            $hasInboxId = Schema::hasColumn('campaigns', 'inbox_id');
            $hasAnalytics = Schema::hasColumn('campaigns', 'analytics');
            $cols = array_values(array_filter([
                'id',
                'type',
                $hasInboxId ? 'inbox_id' : null,
                $hasAnalytics ? 'analytics' : null,
            ]));

            DB::table('campaigns')
                ->select($cols)
                ->orderBy('id')
                ->chunkById(200, function ($campaigns) use ($hasInboxId, $hasAnalytics) {
                    foreach ($campaigns as $campaign) {
                        $tag = $campaign->type === 'occasion' ? 'campaign_occasion' : 'campaign_promotion';
                        $inboxIds = [];

                        if ($hasInboxId && ! empty($campaign->inbox_id)) {
                            $inboxIds[] = (int) $campaign->inbox_id;
                        }

                        if ($hasAnalytics && ! empty($campaign->analytics)) {
                            $analytics = is_string($campaign->analytics)
                                ? json_decode($campaign->analytics, true)
                                : (array) $campaign->analytics;

                            foreach ((array) ($analytics['blast_inbox_ids'] ?? []) as $bid) {
                                if ((int) $bid > 0) {
                                    $inboxIds[] = (int) $bid;
                                }
                            }
                        }

                        $inboxIds = array_values(array_unique($inboxIds));

                        if ($inboxIds !== []) {
                            DB::table('inboxes')
                                ->whereIn('id', $inboxIds)
                                ->update([
                                    'source' => $tag,
                                    'source_id' => $campaign->id,
                                ]);
                        }
                    }
                });
        }

        // Backfill survey SMS (surveys store blast inbox IDs in analytics->blast_inbox_ids).
        if (Schema::hasTable('surveys')) {
            $hasInboxId = Schema::hasColumn('surveys', 'inbox_id');
            $hasAnalytics = Schema::hasColumn('surveys', 'analytics');
            $cols = array_values(array_filter([
                'id',
                $hasInboxId ? 'inbox_id' : null,
                $hasAnalytics ? 'analytics' : null,
            ]));

            if ($hasInboxId || $hasAnalytics) {
                DB::table('surveys')
                    ->select($cols)
                    ->orderBy('id')
                    ->chunkById(200, function ($surveys) use ($hasInboxId, $hasAnalytics) {
                        foreach ($surveys as $survey) {
                            $inboxIds = [];

                            if ($hasInboxId && ! empty($survey->inbox_id)) {
                                $inboxIds[] = (int) $survey->inbox_id;
                            }

                            if ($hasAnalytics && ! empty($survey->analytics)) {
                                $analytics = is_string($survey->analytics)
                                    ? json_decode($survey->analytics, true)
                                    : (array) $survey->analytics;

                                foreach ((array) ($analytics['blast_inbox_ids'] ?? []) as $bid) {
                                    if ((int) $bid > 0) {
                                        $inboxIds[] = (int) $bid;
                                    }
                                }
                            }

                            $inboxIds = array_values(array_unique($inboxIds));

                            if ($inboxIds !== []) {
                                DB::table('inboxes')
                                    ->whereIn('id', $inboxIds)
                                    ->update([
                                        'source' => 'survey',
                                        'source_id' => $survey->id,
                                    ]);
                            }
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inboxes', 'source')) {
            Schema::table('inboxes', function (Blueprint $table) {
                $table->dropIndex(['client_id', 'source', 'created_at']);
                $table->dropIndex(['source']);
                $table->dropColumn(['source', 'source_id']);
            });
        }
    }
};
