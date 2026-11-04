<?php

use App\Jobs\SyncDeliveryReportsJob;
use App\Jobs\VerifyPaymentJob;
use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Payment;
use App\Models\Sent;
use App\Services\Sms\AggregatorBalanceService;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sms:dispatch-pending', function () {
    $count = 0;

    Outbox::query()
        ->where('status', 'pending')
        ->where('created_at', '<', now()->subMinutes(2))
        ->orderBy('id')
        ->limit(500)
        ->pluck('inbox_id')
        ->each(function (int $inboxId) use (&$count) {
            if ($inbox = Inbox::query()->find($inboxId)) {
                SmsService::dispatch($inbox);
                $count++;
            }
        });

    $this->info("Re-dispatched {$count} pending SMS.");
})->purpose('Re-queue SMS whose job never started');

Artisan::command('sms:sync-delivery', function () {
    $ids = Sent::query()
        ->where('status', Sent::SUBMITTED)
        ->whereNotNull('next_dlr_at')
        ->where('next_dlr_at', '<=', now())
        ->orderBy('next_dlr_at')
        ->limit((int) config('sms.dlr.per_minute', 1000))
        ->pluck('id')
        ->all();

    if ($ids === []) {
        return;
    }

    // Lease the rows so the next run does not pick them up while jobs are queued.
    foreach (array_chunk($ids, 1000) as $chunk) {
        Sent::query()->whereIn('id', $chunk)->update(['next_dlr_at' => now()->addMinutes(10)]);
    }

    foreach (array_chunk($ids, (int) config('sms.dlr.job_size', 100)) as $chunk) {
        SyncDeliveryReportsJob::dispatch($chunk);
    }

    $this->info('Queued delivery checks for '.count($ids).' messages.');
})->purpose('Poll the aggregator for delivery receipts');

Artisan::command('sms:check-aggregator-balance', function (AggregatorBalanceService $balances) {
    $result = $balances->refresh();

    $this->info($result['message']);

    if (! $result['in_error']) {
        $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
    }
})->purpose('Snapshot the aggregator balance and alert admins if it is low');

Artisan::command('payments:verify-pending', function () {
    $ids = Payment::query()
        ->where('status', Payment::PENDING)
        ->whereNotNull('gateway')
        ->where('gateway', '!=', 'wallet')
        ->whereNotNull('next_verify_at')
        ->where('next_verify_at', '<=', now())
        ->orderBy('next_verify_at')
        ->limit(500)
        ->pluck('id');

    $ids->each(fn (int $id) => VerifyPaymentJob::dispatch($id));

    $this->info('Queued status checks for '.$ids->count().' payments.');
})->purpose('Check pending gateway payments that are due for a status check');

Schedule::command('payments:verify-pending')->everyMinute()->withoutOverlapping();
Schedule::command('sms:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('sms:sync-delivery')->everyMinute()->withoutOverlapping();
Schedule::command('sms:check-aggregator-balance')->everyFifteenMinutes()->withoutOverlapping();
