<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep engagement/utility rows for audit — drop cascading FKs that would wipe them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'wallet_holds' => ['wallet_holds_client_id_foreign', 'wallet_holds_wallet_id_foreign', 'wallet_holds_payment_id_foreign'],
            'campaigns' => ['campaigns_client_id_foreign', 'campaigns_reward_id_foreign', 'campaigns_wallet_hold_id_foreign', 'campaigns_payment_id_foreign'],
            'campaign_events' => ['campaign_events_campaign_id_foreign'],
            'surveys' => ['surveys_client_id_foreign', 'surveys_reward_id_foreign', 'surveys_wallet_hold_id_foreign', 'surveys_payment_id_foreign'],
            'survey_questions' => ['survey_questions_survey_id_foreign'],
            'survey_responses' => ['survey_responses_survey_id_foreign'],
            'utility_orders' => ['utility_orders_client_id_foreign', 'utility_orders_payment_id_foreign', 'utility_orders_order_id_foreign'],
        ];

        foreach ($map as $table => $keys) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($keys as $key) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$key}`");
                } catch (Throwable) {
                    // Constraint may already be absent.
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty — re-adding cascades would fight the audit requirement.
    }
};
