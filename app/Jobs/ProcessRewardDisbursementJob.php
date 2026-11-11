<?php

namespace App\Jobs;

use App\Services\RewardDisbursementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pays one queued campaign / survey reward (Swift airtime or data, or a Paystack money transfer).
 */
class ProcessRewardDisbursementJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public int $disbursementId)
    {
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return (string) $this->disbursementId;
    }

    public function handle(RewardDisbursementService $rewards): void
    {
        $rewards->process($this->disbursementId);
    }
}
