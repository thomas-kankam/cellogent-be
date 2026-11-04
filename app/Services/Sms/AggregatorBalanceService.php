<?php

namespace App\Services\Sms;

use App\Concerns\ReturnsServiceResult;
use App\Mail\AggregatorLowBalanceMail;
use App\Models\Admin;
use App\Models\AggregatorBalanceSnapshot;
use App\Models\Inbox;
use App\Models\SmsUnitLedger;
use App\Models\Subscription;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Admin view of the aggregator account against what clients hold.
 * Clients' unsent units are a liability on the aggregator credit; when credit
 * cannot cover them (or drops under the threshold) admins are emailed to top up.
 */
class AggregatorBalanceService
{
    use ReturnsServiceResult;

    public function __construct(protected KairosClient $kairos) {}

    /**
     * Fetch the live balance, store a snapshot and alert if low.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function refresh(bool $alert = true): array
    {
        $response = $this->kairos->balance();

        if (! $response->ok) {
            return $this->result(true, 'Could not reach the SMS aggregator: '.$response->error, ApiCode::SERVER, ApiReason::SERVER_ERROR);
        }

        $data = $response->data();
        $credit = (int) ($data['credit'] ?? 0);
        $outstanding = $this->clientUnitsOutstanding();
        $headroom = $credit - $outstanding;

        $snapshot = AggregatorBalanceSnapshot::create([
            'aggregator' => KairosClient::NAME,
            'credit' => $credit,
            'intl_credit' => (int) ($data['intlCredit'] ?? 0),
            'client_units_outstanding' => $outstanding,
            'headroom' => $headroom,
            'is_low' => $this->isLow($credit, $headroom),
        ]);

        if ($alert && $snapshot->is_low) {
            $this->alert($snapshot);
        }

        return $this->result(false, 'Aggregator balance loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($snapshot->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function overview(): array
    {
        $snapshot = AggregatorBalanceSnapshot::query()->latest('id')->first();

        if (! $snapshot || $snapshot->created_at->lt(now()->subMinutes(5))) {
            $fresh = $this->refresh();

            if (! $fresh['in_error']) {
                $snapshot = AggregatorBalanceSnapshot::query()->latest('id')->first();
            }
        }

        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        return $this->result(false, 'SMS overview loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'aggregator' => $snapshot ? $this->present($snapshot) : null,
            'clients' => [
                'units_outstanding' => $this->clientUnitsOutstanding(),
                'active_subscriptions' => $this->activeSubscriptions()->count(),
            ],
            'usage' => [
                'today' => $this->usageSince($today),
                'this_month' => $this->usageSince($month),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AggregatorBalanceSnapshot $snapshot): array
    {
        return [
            'name' => $snapshot->aggregator,
            'credit' => (int) $snapshot->credit,
            'intl_credit' => (int) $snapshot->intl_credit,
            'client_units_outstanding' => (int) $snapshot->client_units_outstanding,
            'headroom' => (int) $snapshot->headroom,
            'top_up_needed' => max(0, -1 * (int) $snapshot->headroom),
            'low_balance_threshold' => (int) config('sms.alerts.low_balance_threshold'),
            'is_low' => (bool) $snapshot->is_low,
            'checked_at' => optional($snapshot->created_at)->toDateTimeString(),
        ];
    }

    public function clientUnitsOutstanding(): int
    {
        return (int) $this->activeSubscriptions()->sum('units');
    }

    protected function activeSubscriptions()
    {
        return Subscription::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    protected function isLow(int $credit, int $headroom): bool
    {
        return $credit <= (int) config('sms.alerts.low_balance_threshold') || $headroom < 0;
    }

    /**
     * @return array<string, int>
     */
    protected function usageSince($from): array
    {
        $ledger = SmsUnitLedger::query()
            ->where('created_at', '>=', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN units ELSE 0 END), 0) AS debited")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'refund' THEN units ELSE 0 END), 0) AS refunded")
            ->first();

        $messages = Inbox::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('COALESCE(SUM(submitted_count), 0) AS sent')
            ->selectRaw('COALESCE(SUM(delivered_count), 0) AS delivered')
            ->selectRaw('COALESCE(SUM(failed_count), 0) AS failed')
            ->selectRaw('COALESCE(SUM(CASE WHEN admin_id IS NOT NULL THEN units_used ELSE 0 END), 0) AS admin_units')
            ->first();

        return [
            'client_units_debited' => (int) $ledger->debited,
            'client_units_refunded' => (int) $ledger->refunded,
            'client_units_net' => (int) $ledger->debited - (int) $ledger->refunded,
            'admin_units' => (int) $messages->admin_units,
            'messages_sent' => (int) $messages->sent,
            'messages_delivered' => (int) $messages->delivered,
            'messages_failed' => (int) $messages->failed,
        ];
    }

    protected function alert(AggregatorBalanceSnapshot $snapshot): void
    {
        $cooldown = (int) config('sms.alerts.cooldown_minutes', 360);

        if (! Cache::add('sms:aggregator-low-balance-alert', true, now()->addMinutes($cooldown))) {
            return;
        }

        $emails = config('sms.alerts.emails') ?: Admin::query()->where('status', 'active')->pluck('email')->all();

        if ($emails === []) {
            return;
        }

        Mail::to($emails)->queue((new AggregatorLowBalanceMail($this->present($snapshot)))->onQueue('email-triggers'));

        $snapshot->update(['alert_sent' => true]);
    }
}
