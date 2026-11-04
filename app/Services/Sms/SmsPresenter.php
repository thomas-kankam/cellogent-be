<?php

namespace App\Services\Sms;

use App\Models\Inbox;
use App\Models\Sent;

/**
 * Client output never includes aggregator names, ids or raw errors.
 * Admin output adds them on top of the client shape.
 */
class SmsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function forClient(Inbox $inbox): array
    {
        $pending = max(0, $inbox->recipients_count - $inbox->delivered_count - $inbox->failed_count);

        return [
            'id' => $inbox->id,
            'resource' => 'sms',
            'type' => $inbox->type,
            'request_type' => $inbox->request_type,
            'mode' => $inbox->mode,
            'parent_id' => $inbox->parent_id,
            'from' => $inbox->from,
            'to' => $inbox->isQuick() ? $inbox->recipients() : null,
            'contact_group_ids' => $inbox->contact_group_ids,
            'message' => $inbox->message,
            'parts' => (int) $inbox->parts,
            'recipients' => (int) $inbox->recipients_count,
            'units_used' => (int) $inbox->units_used,
            'units_refunded' => (int) $inbox->units_refunded,
            'units_before' => $inbox->previous_units !== null ? (int) $inbox->previous_units : null,
            'status' => $inbox->status,
            'delivery' => [
                'sent' => (int) $inbox->submitted_count,
                'delivered' => (int) $inbox->delivered_count,
                'failed' => (int) $inbox->failed_count,
                'pending' => $pending,
            ],
            'created_at' => optional($inbox->created_at)->toDateTimeString(),
            'completed_at' => optional($inbox->completed_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Inbox $inbox): array
    {
        return $this->forClient($inbox) + [
            'client_id' => $inbox->client_id,
            'admin_id' => $inbox->admin_id,
            'aggregator' => $inbox->aggregator,
            'message_id' => $inbox->message_id,
            'is_global' => (bool) $inbox->is_global,
            'batches' => [
                'total' => (int) $inbox->batches_total,
                'done' => (int) $inbox->batches_done,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientForClient(Sent $sent): array
    {
        return [
            'id' => $sent->id,
            'to' => $sent->to,
            'status' => $sent->status,
            'failure_reason' => $this->failureReason($sent),
            'units_used' => (int) $sent->units_used,
            'units_refunded' => (bool) $sent->units_refunded,
            'sent_at' => optional($sent->submitted_at)->toDateTimeString(),
            'delivered_at' => optional($sent->delivered_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientForAdmin(Sent $sent): array
    {
        return $this->recipientForClient($sent) + [
            'inbox_id' => $sent->inbox_id,
            'client_id' => $sent->client_id,
            'aggregator' => $sent->aggregator,
            'message_id' => $sent->message_id,
            'sequence_no' => $sent->sequence_no,
            'job_id' => $sent->job_id,
            'dlr_status' => $sent->dlr_status,
            'dlr_attempts' => (int) $sent->dlr_attempts,
            'error' => $sent->error,
        ];
    }

    protected function failureReason(Sent $sent): ?string
    {
        if ($sent->status !== Sent::FAILED) {
            return null;
        }

        return match (strtoupper((string) $sent->dlr_status)) {
            'UNDELIVERED' => 'undelivered',
            'REJECTED' => 'rejected',
            'EXPIRED' => 'expired',
            default => 'not_sent',
        };
    }
}
