<?php

namespace App\Jobs;

use App\Services\Sms\AggregatorBalanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckAggregatorBalanceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(AggregatorBalanceService $balances): void
    {
        $balances->refresh();
    }
}
