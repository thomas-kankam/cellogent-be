<?php

use App\Jobs\SyncDeliveryReportsJob;
use App\Jobs\VerifyPaymentJob;
use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\Payment;
use App\Models\Sent;
use App\Services\ContactBeneficiaryService;
use App\Services\Engagement\EngagementBlastService;
use App\Services\Engagement\OccasionService;
use App\Services\RewardDisbursementService;
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

    // Short lease so a stuck/slow worker does not block re-poll for long.
    $leaseSeconds = (int) config('sms.dlr.lease_seconds', 90);

    foreach (array_chunk($ids, 1000) as $chunk) {
        Sent::query()->whereIn('id', $chunk)->update([
            'next_dlr_at' => now()->addSeconds($leaseSeconds),
        ]);
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
Schedule::command('sms:sync-delivery')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('sms:check-aggregator-balance')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('engagement:dispatch-due', function (EngagementBlastService $blasts) {
    $count = $blasts->dispatchDue();

    $this->info("Queued {$count} campaign/survey SMS blast(s).");
})->purpose('Send promotions and surveys that are live (or whose send time has come) and not sent yet');

Artisan::command('campaigns:run-occasions', function (OccasionService $occasions) {
    $result = $occasions->run();

    $this->info("Occasion campaigns: {$result['campaigns']} run, {$result['sent']} sent, {$result['skipped']} already sent, {$result['failed']} failed.");
})->purpose('Send birthday and other occasion campaigns that are due today (once per person per year)');

Artisan::command('rewards:process-pending', function (RewardDisbursementService $rewards) {
    $result = $rewards->dispatchPending();

    $this->info("Rewards: {$result['dispatched']} queued, {$result['reset']} reset, {$result['reconciled']} checked with the provider.");
})->purpose('Pay queued rewards, retry failed attempts and check rewards waiting on a provider');

Artisan::command('contacts:verify-beneficiaries', function (ContactBeneficiaryService $beneficiaries) {
    $result = $beneficiaries->dispatchPendingVerify();

    $this->info("Beneficiary verify: {$result['groups']} group(s), ~{$result['contacts']} contact(s) queued.");
})->purpose('Check unknown/submitted contact numbers with Swift (daily at midnight)');

Artisan::command('contacts:submit-beneficiaries', function (ContactBeneficiaryService $beneficiaries) {
    $result = $beneficiaries->dispatchPendingSubmit();

    $this->info("Beneficiary submit: {$result['groups']} group(s), ~{$result['contacts']} contact(s) queued.");
})->purpose('Submit unverified contact numbers to Swift (weekly)');

Schedule::command('engagement:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:run-occasions')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('rewards:process-pending')->everyMinute()->withoutOverlapping();
// Submit Sunday 22:00 → midnight verifies catch submitted → verified by morning.
// Schedule::command('contacts:submit-beneficiaries')->weeklyOn(0, '22:00')->withoutOverlapping();
// Schedule::command('contacts:verify-beneficiaries')->dailyAt('00:00')->withoutOverlapping();
