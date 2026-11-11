<?php

namespace App\Jobs;

use App\Services\Engagement\EngagementBlastService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the SMS for a promotion campaign or a survey once it is live (or its send_at has passed).
 * Safe to dispatch more than once: the blast is claimed in the database and only goes out once.
 */
class DispatchEngagementBlastJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    /**
     * @param  'campaign'|'survey'  $kind
     */
    public function __construct(public string $kind, public int $itemId) {}

    public function uniqueId(): string
    {
        return $this->kind.':'.$this->itemId;
    }

    public function handle(EngagementBlastService $blasts): void
    {
        $blasts->blast($this->kind, $this->itemId);
    }
}
