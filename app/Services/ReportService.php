<?php

namespace App\Services;

use App\Concerns\ReturnsServiceResult;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated reports grouped by period (daily, weekly, monthly, yearly, custom range).
 * $clientId null = all clients (admin); otherwise scoped to one client.
 */
class ReportService
{
    use ReturnsServiceResult;

    public const CLIENT_REPORTS = ['sms', 'delivery', 'payments', 'wallet', 'subscriptions'];

    public const ADMIN_REPORTS = ['sms', 'delivery', 'payments', 'wallet', 'subscriptions', 'revenue'];

    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'custom'];

    /**
     * @param  array<string, mixed>  $filters  period, from, to, type, request_type, purpose, status
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function run(string $report, ?int $clientId, array $filters): array
    {
        $range = $this->range($filters['period'] ?? 'daily', $filters['from'] ?? null, $filters['to'] ?? null);

        if (is_string($range)) {
            return $this->result(true, $range, ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, ['period' => [$range]]);
        }

        $data = match ($report) {
            'sms' => $this->sms($clientId, $range, $filters),
            'delivery' => $this->delivery($clientId, $range, $filters),
            'payments' => $this->payments($clientId, $range, $filters),
            'wallet' => $this->wallet($clientId, $range, $filters),
            'subscriptions' => $this->subscriptions($clientId, $range),
            'revenue' => $this->revenue($clientId, $range),
            default => null,
        };

        if ($data === null) {
            return $this->result(true, 'Unknown report.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->result(false, 'Report loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'report' => $report,
            'period' => $range['period'],
            'group_by' => $range['group_by'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
        ] + $data);
    }

    /**
     * @return array{period: string, group_by: string, from: CarbonImmutable, to: CarbonImmutable}|string
     */
    public function range(string $period, ?string $from, ?string $to): array|string
    {
        if (! in_array($period, self::PERIODS, true)) {
            return 'Period must be one of: '.implode(', ', self::PERIODS).'.';
        }

        $now = CarbonImmutable::now();

        try {
            $start = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
            $end = $to ? CarbonImmutable::parse($to)->endOfDay() : null;
        } catch (\Throwable) {
            return 'Dates must be in Y-m-d format.';
        }

        if ($period === 'custom' && (! $start || ! $end)) {
            return 'A custom period needs both from and to dates.';
        }

        $end ??= $now->endOfDay();
        $start ??= match ($period) {
            'daily' => $end->subDays(29)->startOfDay(),
            'weekly' => $end->subWeeks(11)->startOfWeek(),
            'monthly' => $end->subMonths(11)->startOfMonth(),
            default => $end->subYears(4)->startOfYear(),
        };

        if ($start->gt($end)) {
            return 'The from date must be before the to date.';
        }

        $groupBy = $period === 'custom'
            ? ($start->diffInDays($end) <= 62 ? 'daily' : 'monthly')
            : $period;

        return ['period' => $period, 'group_by' => $groupBy, 'from' => $start, 'to' => $end];
    }

    public function bucket(string $column, string $groupBy): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $format = ['daily' => '%Y-%m-%d', 'weekly' => '%Y-W%W', 'monthly' => '%Y-%m', 'yearly' => '%Y'][$groupBy];

            return "strftime('{$format}', {$column})";
        }

        $format = ['daily' => '%Y-%m-%d', 'weekly' => '%x-W%v', 'monthly' => '%Y-%m', 'yearly' => '%Y'][$groupBy];

        return "DATE_FORMAT({$column}, '{$format}')";
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function sms(?int $clientId, array $range, array $filters): array
    {
        $bucket = $this->bucket('created_at', $range['group_by']);

        $rows = $this->scoped('inboxes', $clientId, $range)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v))
            ->selectRaw("{$bucket} AS bucket, type, request_type")
            ->selectRaw('COUNT(*) AS messages')
            ->selectRaw('COALESCE(SUM(recipients_count), 0) AS recipients')
            ->selectRaw('COALESCE(SUM(units_used), 0) AS units_used')
            ->selectRaw('COALESCE(SUM(units_refunded), 0) AS units_refunded')
            ->selectRaw('COALESCE(SUM(submitted_count), 0) AS sent')
            ->selectRaw('COALESCE(SUM(delivered_count), 0) AS delivered')
            ->selectRaw('COALESCE(SUM(failed_count), 0) AS failed')
            ->groupByRaw("{$bucket}, type, request_type")
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'bucket' => $r->bucket,
                'type' => $r->type,
                'request_type' => $r->request_type,
                'messages' => (int) $r->messages,
                'recipients' => (int) $r->recipients,
                'units_used' => (int) $r->units_used,
                'units_refunded' => (int) $r->units_refunded,
                'units_net' => (int) $r->units_used - (int) $r->units_refunded,
                'sent' => (int) $r->sent,
                'delivered' => (int) $r->delivered,
                'failed' => (int) $r->failed,
            ])->all();

        $by = fn (string $key) => collect($rows)->groupBy($key)->map(fn ($g) => [
            'messages' => $g->sum('messages'),
            'recipients' => $g->sum('recipients'),
            'units_net' => $g->sum('units_net'),
        ])->all();

        return [
            'rows' => $rows,
            'by_type' => $by('type'),
            'by_channel' => $by('request_type'),
            'totals' => $this->sum($rows, ['messages', 'recipients', 'units_used', 'units_refunded', 'units_net', 'sent', 'delivered', 'failed']),
        ];
    }

    /**
     * SMS lifecycle: requested → sent → delivered / failed (with failure reasons).
     *
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function delivery(?int $clientId, array $range, array $filters): array
    {
        $bucket = $this->bucket('created_at', $range['group_by']);
        $inboxes = fn () => $this->scoped('inboxes', $clientId, $range)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v));

        $rows = $inboxes()
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COALESCE(SUM(recipients_count), 0) AS recipients')
            ->selectRaw('COALESCE(SUM(submitted_count), 0) AS sent')
            ->selectRaw('COALESCE(SUM(delivered_count), 0) AS delivered')
            ->selectRaw('COALESCE(SUM(failed_count), 0) AS failed')
            ->groupByRaw($bucket)
            ->orderBy('bucket')
            ->get()
            ->map(function ($r) {
                $recipients = (int) $r->recipients;
                $delivered = (int) $r->delivered;
                $failed = (int) $r->failed;

                return [
                    'bucket' => $r->bucket,
                    'recipients' => $recipients,
                    'sent' => (int) $r->sent,
                    'delivered' => $delivered,
                    'failed' => $failed,
                    'pending' => max(0, $recipients - $delivered - $failed),
                    'delivery_rate' => $recipients > 0 ? round($delivered / $recipients * 100, 2) : 0.0,
                ];
            })->all();

        $reasons = DB::table('sents')
            ->whereIn('inbox_id', $inboxes()->select('id'))
            ->where('status', 'failed')
            ->selectRaw('dlr_status, COUNT(*) AS total')
            ->groupBy('dlr_status')
            ->pluck('total', 'dlr_status');

        $failureReasons = ['not_sent' => 0, 'undelivered' => 0, 'rejected' => 0, 'expired' => 0];

        foreach ($reasons as $status => $total) {
            $key = match (strtoupper((string) $status)) {
                'UNDELIVERED' => 'undelivered',
                'REJECTED' => 'rejected',
                'EXPIRED' => 'expired',
                default => 'not_sent',
            };
            $failureReasons[$key] += (int) $total;
        }

        $totals = $this->sum($rows, ['recipients', 'sent', 'delivered', 'failed', 'pending']);
        $totals['delivery_rate'] = $totals['recipients'] > 0 ? round($totals['delivered'] / $totals['recipients'] * 100, 2) : 0.0;

        return ['rows' => $rows, 'failure_reasons' => $failureReasons, 'totals' => $totals];
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function payments(?int $clientId, array $range, array $filters): array
    {
        $bucket = $this->bucket('created_at', $range['group_by']);

        $rows = $this->scoped('payments', $clientId, $range)
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->selectRaw("{$bucket} AS bucket, purpose, status")
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->selectRaw('COALESCE(SUM(charge), 0) AS charge')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total_amount')
            ->groupByRaw("{$bucket}, purpose, status")
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'bucket' => $r->bucket,
                'purpose' => $r->purpose,
                'status' => $r->status,
                'payments' => (int) $r->payments,
                'amount' => round((float) $r->amount, 2),
                'charge' => round((float) $r->charge, 2),
                'total_amount' => round((float) $r->total_amount, 2),
            ])->all();

        $paid = array_values(array_filter($rows, fn ($r) => $r['status'] === 'paid'));

        return [
            'rows' => $rows,
            'totals' => [
                'payments' => array_sum(array_column($rows, 'payments')),
                'paid' => array_sum(array_column($paid, 'payments')),
                'pending' => array_sum(array_column(array_filter($rows, fn ($r) => $r['status'] === 'pending'), 'payments')),
                'failed' => array_sum(array_column(array_filter($rows, fn ($r) => $r['status'] === 'failed'), 'payments')),
                'paid_amount' => round(array_sum(array_column($paid, 'amount')), 2),
                'paid_charge' => round(array_sum(array_column($paid, 'charge')), 2),
                'paid_total' => round(array_sum(array_column($paid, 'total_amount')), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function wallet(?int $clientId, array $range, array $filters): array
    {
        $bucket = $this->bucket('created_at', $range['group_by']);

        $rows = $this->scoped('wallet_transactions', $clientId, $range)
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->selectRaw("{$bucket} AS bucket, type, purpose")
            ->selectRaw('COUNT(*) AS transactions')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->groupByRaw("{$bucket}, type, purpose")
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'bucket' => $r->bucket,
                'type' => $r->type,
                'purpose' => $r->purpose,
                'transactions' => (int) $r->transactions,
                'amount' => round((float) $r->amount, 2),
            ])->all();

        $credits = round(array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'credit'), 'amount')), 2);
        $debits = round(array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'debit'), 'amount')), 2);

        $balance = DB::table('wallets')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->sum('balance');

        return [
            'rows' => $rows,
            'totals' => [
                'credits' => $credits,
                'debits' => $debits,
                'net' => round($credits - $debits, 2),
                'current_balance' => round((float) $balance, 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    protected function subscriptions(?int $clientId, array $range): array
    {
        $bucket = $this->bucket('created_at', $range['group_by']);

        $rows = $this->scoped('subscription_archieves', $clientId, $range)
            ->selectRaw("{$bucket} AS bucket, plan_name")
            ->selectRaw('COUNT(*) AS subscriptions')
            ->selectRaw('COALESCE(SUM(units), 0) AS units')
            ->selectRaw('COALESCE(SUM(plan_price), 0) AS amount')
            ->groupByRaw("{$bucket}, plan_name")
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'bucket' => $r->bucket,
                'plan_name' => $r->plan_name,
                'subscriptions' => (int) $r->subscriptions,
                'units' => (int) $r->units,
                'amount' => round((float) $r->amount, 2),
            ])->all();

        return ['rows' => $rows, 'totals' => $this->sum($rows, ['subscriptions', 'units', 'amount'])];
    }

    /**
     * Admin: money collected per gateway and what we earned in charges.
     *
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    protected function revenue(?int $clientId, array $range): array
    {
        $bucket = $this->bucket('paid_at', $range['group_by']);

        $rows = DB::table('payments')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$range['from'], $range['to']])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->selectRaw("{$bucket} AS bucket, gateway, purpose")
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->selectRaw('COALESCE(SUM(charge), 0) AS charge_earned')
            ->selectRaw('COALESCE(SUM(gateway_charge), 0) AS gateway_charge')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS collected')
            ->groupByRaw("{$bucket}, gateway, purpose")
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'bucket' => $r->bucket,
                'gateway' => $r->gateway,
                'purpose' => $r->purpose,
                'payments' => (int) $r->payments,
                'amount' => round((float) $r->amount, 2),
                'charge_earned' => round((float) $r->charge_earned, 2),
                'gateway_charge' => round((float) $r->gateway_charge, 2),
                'collected' => round((float) $r->collected, 2),
            ])->all();

        return ['rows' => $rows, 'totals' => $this->sum($rows, ['payments', 'amount', 'charge_earned', 'gateway_charge', 'collected'])];
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function scoped(string $table, ?int $clientId, array $range): Builder
    {
        return DB::table($table)
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, int|float>
     */
    protected function sum(array $rows, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $total = array_sum(array_column($rows, $key));
            $out[$key] = is_float($total) ? round($total, 2) : $total;
        }

        return $out;
    }
}
