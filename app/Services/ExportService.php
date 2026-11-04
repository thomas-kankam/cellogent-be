<?php

namespace App\Services;

use App\Models\Inbox;
use App\Models\Payment;
use App\Models\Sent;
use App\Models\SubscriptionArchive;
use App\Models\WalletTransaction;
use App\Support\TabularExport;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Row-level exports (one line per record), streamed so large ranges stay in constant memory.
 * $clientId null = admin export across all clients.
 */
class ExportService
{
    public const DATASETS = ['payments', 'wallet', 'subscriptions', 'sms', 'sms-delivery'];

    public function __construct(protected ReportService $reports) {}

    /**
     * @param  array<string, mixed>  $filters  period, from, to, format, type, request_type, status, purpose, inbox_id
     */
    public function download(string $dataset, ?int $clientId, array $filters, bool $admin = false): BinaryFileResponse|string
    {
        $range = $this->reports->range($filters['period'] ?? 'daily', $filters['from'] ?? null, $filters['to'] ?? null);

        if (is_string($range)) {
            return $range;
        }

        [$headers, $rows] = match ($dataset) {
            'payments' => $this->payments($clientId, $range, $filters, $admin),
            'wallet' => $this->wallet($clientId, $range, $filters),
            'subscriptions' => $this->subscriptions($clientId, $range),
            'sms' => $this->sms($clientId, $range, $filters, $admin),
            'sms-delivery' => $this->delivery($clientId, $range, $filters, $admin),
            default => [null, null],
        };

        if ($headers === null) {
            return 'Unknown export.';
        }

        return TabularExport::download($dataset, $headers, $rows, $filters['format'] ?? 'csv');
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: \Generator}
     */
    protected function payments(?int $clientId, array $range, array $filters, bool $admin): array
    {
        $headers = ['Reference', 'Purpose', 'Method', 'Network', 'Phone', 'Amount', 'Charge', 'Total', 'Currency', 'Status', 'Failure reason', 'Paid at', 'Created at'];

        if ($admin) {
            array_unshift($headers, 'Client ID');
            array_push($headers, 'Gateway', 'Gateway reference', 'Gateway charge');
        }

        $query = $this->scoped(Payment::query(), $clientId, $range)
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $rows = (function () use ($query, $admin) {
            foreach ($query->lazyById(2000) as $p) {
                $row = [$p->reference_id, $p->purpose, $p->method, $p->network, $p->msisdn, $p->amount, $p->charge, $p->total_amount, $p->currency, $p->status, $p->failure_reason, $p->paid_at, $p->created_at];

                if ($admin) {
                    array_unshift($row, $p->client_id);
                    array_push($row, $p->gateway, $p->gateway_reference, $p->gateway_charge);
                }

                yield $row;
            }
        })();

        return [$headers, $rows];
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: \Generator}
     */
    protected function wallet(?int $clientId, array $range, array $filters): array
    {
        $headers = ['Client ID', 'Type', 'Purpose', 'Amount', 'Balance before', 'Balance after', 'Payment ID', 'Description', 'Date'];

        $query = $this->scoped(WalletTransaction::query(), $clientId, $range)
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v));

        $rows = (function () use ($query) {
            foreach ($query->lazyById(2000) as $t) {
                yield [$t->client_id, $t->type, $t->purpose, $t->amount, $t->balance_before, $t->balance_after, $t->payment_id, $t->description, $t->created_at];
            }
        })();

        return [$headers, $rows];
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array{0: list<string>, 1: \Generator}
     */
    protected function subscriptions(?int $clientId, array $range): array
    {
        $headers = ['Client ID', 'Plan', 'Plan type', 'Units', 'Price', 'Months', 'Starts at', 'Ends at', 'Payment status', 'Payment reference', 'Date'];

        $query = $this->scoped(SubscriptionArchive::query(), $clientId, $range);

        $rows = (function () use ($query) {
            foreach ($query->lazyById(2000) as $s) {
                yield [$s->client_id, $s->plan_name, $s->plan_type, $s->units, $s->plan_price, $s->duration_in_months, $s->starts_at, $s->ends_at, $s->payment_status, $s->payment_reference, $s->created_at];
            }
        })();

        return [$headers, $rows];
    }

    /**
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: \Generator}
     */
    protected function sms(?int $clientId, array $range, array $filters, bool $admin): array
    {
        $headers = ['ID', 'Client ID', 'Type', 'Channel', 'Mode', 'Sender ID', 'Message', 'Parts', 'Recipients', 'Units used', 'Units refunded', 'Sent', 'Delivered', 'Failed', 'Status', 'Created at', 'Completed at'];

        if ($admin) {
            $headers[] = 'Aggregator';
        }

        $query = $this->scoped(Inbox::query(), $clientId, $range)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $rows = (function () use ($query, $admin) {
            foreach ($query->lazyById(2000) as $i) {
                $row = [$i->id, $i->client_id, $i->type, $i->request_type, $i->mode, $i->from, $i->message, $i->parts, $i->recipients_count, $i->units_used, $i->units_refunded, $i->submitted_count, $i->delivered_count, $i->failed_count, $i->status, $i->created_at, $i->completed_at];

                if ($admin) {
                    $row[] = $i->aggregator;
                }

                yield $row;
            }
        })();

        return [$headers, $rows];
    }

    /**
     * Per-recipient lifecycle: queued → submitted → delivered / failed.
     *
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $filters
     * @return array{0: list<string>, 1: \Generator}
     */
    protected function delivery(?int $clientId, array $range, array $filters, bool $admin): array
    {
        $headers = ['Message ID', 'Client ID', 'Type', 'Channel', 'Recipient', 'Sender ID', 'Units', 'Status', 'Delivery status', 'Error', 'Refunded', 'Created at', 'Submitted at', 'Delivered at'];

        if ($admin) {
            $headers[] = 'Aggregator';
        }

        $inboxIds = $this->scoped(Inbox::query(), $clientId, $range)
            ->when($filters['inbox_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['request_type'] ?? null, fn ($q, $v) => $q->where('request_type', $v))
            ->select('id');

        $query = Sent::query()
            ->whereIn('inbox_id', $inboxIds)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $rows = (function () use ($query, $admin) {
            foreach ($query->lazyById(2000) as $s) {
                $row = [$s->inbox_id, $s->client_id, $s->type, $s->request_type, $s->to, $s->from, $s->units_used, $s->status, $s->dlr_status, $admin ? $s->error : ($s->status === 'failed' ? 'Not delivered' : null), $s->units_refunded, $s->created_at, $s->submitted_at, $s->delivered_at];

                if ($admin) {
                    $row[] = $s->aggregator;
                }

                yield $row;
            }
        })();

        return [$headers, $rows];
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function scoped(Builder $query, ?int $clientId, array $range): Builder
    {
        return $query
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));
    }
}
