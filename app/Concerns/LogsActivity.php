<?php

namespace App\Concerns;

use App\Services\ActivityLogService;

/**
 * Lets any service write an activity log without constructing the service by hand.
 */
trait LogsActivity
{
    protected function activity(): ActivityLogService
    {
        return app(ActivityLogService::class);
    }
}
