<?php

namespace App\Jobs;

use App\Models\UtilityOrder;
use App\Services\Utilities\UtilityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a paid utility order (airtime, data, ECG, TV, MashUp) to the provider.
 * Runs after the payment transaction commits so a slow provider never holds database locks.
 */
class ProcessUtilityOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 60;

    public function __construct(public int $utilityOrderId)
    {
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return (string) $this->utilityOrderId;
    }

    public function handle(UtilityService $utilities): void
    {
        $order = UtilityOrder::query()->find($this->utilityOrderId);

        if ($order) {
            $utilities->process($order);
        }
    }
}
