<?php

namespace App\Jobs;

use App\Services\ContactBeneficiaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies (or submits) one page of a contact group's numbers with Swift, then queues the next page.
 */
class ProcessBeneficiariesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  'verify'|'submit'  $action
     * @param  list<string>|null  $phones  normalized numbers, or null for the whole group
     */
    public function __construct(
        public string $action,
        public int $clientId,
        public int $groupId,
        public ?array $phones = null,
        public int $afterId = 0,
    ) {
        $this->onQueue('contacts-upload');
    }

    public function handle(ContactBeneficiaryService $beneficiaries): void
    {
        $next = $beneficiaries->runPage($this->action, $this->clientId, $this->groupId, $this->phones, $this->afterId);

        if ($next !== null) {
            self::dispatch($this->action, $this->clientId, $this->groupId, $this->phones, $next);

            return;
        }

        // Whole-group chains mark a lock so upload finalize + the scheduler do not stack starters.
        if ($this->phones === null) {
            Cache::forget($this->runLockKey());
        }
    }

    public function failed(?\Throwable $e): void
    {
        if ($this->phones === null) {
            Cache::forget($this->runLockKey());
        }
    }

    public function runLockKey(): string
    {
        return "beneficiaries:{$this->action}:group:{$this->groupId}";
    }
}
