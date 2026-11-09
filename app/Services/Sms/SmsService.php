<?php

namespace App\Services\Sms;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\PrepareBulkSmsJob;
use App\Jobs\ProcessQuickSmsJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Inbox;
use App\Models\Outbox;
use App\Models\SenderId;
use App\Models\SenderIdBlacklist;
use App\Models\Sent;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use App\Support\SmsParts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SmsService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function __construct(
        protected SmsUnitService $units,
        protected SmsPresenter $presenter,
    ) {}

    /**
     * @param  array{from: string, to: list<string>, message: string}  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function quick(Client $client, array $input, string $requestType): array
    {
        $sender = $this->clientSender($client, $input['from']);

        if (is_array($sender)) {
            return $sender;
        }

        $recipients = $this->recipients($input['to']);

        if ($recipients['invalid'] !== []) {
            return $this->result(true, 'Some phone numbers are not valid.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'to' => $recipients['invalid'],
            ]);
        }

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => 'quick',
                'request_type' => $requestType,
                'from' => $sender,
                'to' => implode(',', $recipients['valid']),
                'message' => $input['message'],
            ],
            recipients: count($recipients['valid']),
        );
    }

    /**
     * @param  array{from: string, contact_group_ids: list<int>, message: string}  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function bulk(Client $client, array $input, string $requestType): array
    {
        $sender = $this->clientSender($client, $input['from']);

        if (is_array($sender)) {
            return $sender;
        }

        $groupIds = array_values(array_unique(array_map('intval', $input['contact_group_ids'])));
        $owned = ContactGroup::query()
            ->where('client_id', $client->id)
            ->whereIn('id', $groupIds)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($groupIds, $owned));

        if ($missing !== []) {
            return $this->result(true, 'Some contact groups were not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND, [
                'contact_group_ids' => $missing,
            ]);
        }

        $audience = (int) $this->audience($client->id, $groupIds)->distinct()->count('phone_number');

        if ($audience === 0) {
            return $this->result(true, 'These contact groups have no contacts that can receive SMS.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => 'bulk',
                'request_type' => $requestType,
                'from' => $sender,
                'contact_group_ids' => $groupIds,
                'message' => $input['message'],
            ],
            recipients: $audience,
        );
    }

    /**
     * Send again to the recipients of $source that failed (their units were already refunded).
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function reblast(Client $client, Inbox $source, ?string $message, string $requestType): array
    {
        if (in_array($source->status, [Inbox::STATUS_QUEUED, Inbox::STATUS_PROCESSING], true)) {
            return $this->result(true, 'This message is still sending. Try again when it finishes.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $failed = Sent::query()
            ->where('inbox_id', $source->id)
            ->where('status', Sent::FAILED)
            ->whereNull('retry_inbox_id')
            ->count();

        if ($failed === 0) {
            return $this->result(true, 'There are no failed recipients to send to.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $sender = $this->clientSender($client, $source->from);

        if (is_array($sender)) {
            return $sender;
        }

        $useQuick = $source->isQuick() && $failed <= (int) config('sms.quick.max_recipients', 100);

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => $useQuick ? 'quick' : 'bulk',
                'request_type' => $requestType,
                'mode' => 'reblast',
                'parent_id' => $source->id,
                'from' => $sender,
                'message' => $message ?: $source->message,
            ],
            recipients: function (Inbox $inbox) use ($source, $useQuick) {
                $claimed = Sent::query()
                    ->where('inbox_id', $source->id)
                    ->where('status', Sent::FAILED)
                    ->whereNull('retry_inbox_id')
                    ->update(['retry_inbox_id' => $inbox->id]);

                if ($useQuick) {
                    $inbox->to = Sent::query()->where('retry_inbox_id', $inbox->id)->pluck('to')->implode(',');
                }

                return $claimed;
            },
        );
    }

    /**
     * Resend a quick message with new text using the aggregator's resend (same recipients).
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function resend(Client $client, Inbox $source, string $message, string $requestType): array
    {
        if (! $source->isQuick()) {
            return $this->result(true, 'Only quick messages can be resent. Use re-blast for bulk messages.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if (in_array($source->status, [Inbox::STATUS_QUEUED, Inbox::STATUS_PROCESSING], true)) {
            return $this->result(true, 'This message is still sending. Try again when it finishes.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $recipients = Sent::query()
            ->where('inbox_id', $source->id)
            ->whereNotNull('message_id')
            ->pluck('to')
            ->all();

        if ($recipients === []) {
            return $this->result(true, 'This message has nothing that can be resent.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $sender = $this->clientSender($client, $source->from);

        if (is_array($sender)) {
            return $sender;
        }

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => 'quick',
                'request_type' => $requestType,
                'mode' => 'resend',
                'parent_id' => $source->id,
                'from' => $sender,
                'to' => implode(',', $recipients),
                'message' => $message,
            ],
            recipients: count($recipients),
        );
    }

    /**
     * Admin send: no client units are used; the aggregator balance pays directly.
     *
     * @param  array{from: string, to: list<string>, message: string}  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function adminQuick(Admin $admin, array $input): array
    {
        if ($blocked = SenderIdBlacklist::match($input['from'])) {
            return $this->result(true, 'This sender ID is blacklisted.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_ALLOWED, [
                'blacklist_id' => $blocked->id,
            ]);
        }

        $recipients = $this->recipients($input['to']);

        if ($recipients['invalid'] !== []) {
            return $this->result(true, 'Some phone numbers are not valid.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'to' => $recipients['invalid'],
            ]);
        }

        return $this->queue(
            client: null,
            admin: $admin,
            attributes: [
                'type' => 'quick',
                'request_type' => 'system',
                'from' => $input['from'],
                'to' => implode(',', $recipients['valid']),
                'message' => $input['message'],
            ],
            recipients: count($recipients['valid']),
        );
    }

    /**
     * Contacts in the groups that can receive SMS (not DND, not in an upload being deleted).
     *
     * @param  list<int>  $groupIds
     */
    public function audience(int $clientId, array $groupIds): Builder
    {
        return Contact::query()
            ->visible()
            ->where('client_id', $clientId)
            ->whereIn('contact_group_id', $groupIds)
            ->where(fn ($q) => $q->whereNull('dnd_status')->orWhere('dnd_status', '!=', 'active'));
    }

    public static function dispatch(Inbox $inbox): void
    {
        if ($inbox->type === 'quick') {
            ProcessQuickSmsJob::dispatch($inbox->id)
                ->onQueue($inbox->request_type === 'api' ? 'sms-api' : 'sms-blast-quick');

            return;
        }

        PrepareBulkSmsJob::dispatch($inbox->id);
    }

    /**
     * Create inbox + outbox, take units, then queue the job.
     *
     * @param  array<string, mixed>  $attributes
     * @param  int|callable(Inbox): int  $recipients
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function queue(?Client $client, ?Admin $admin, array $attributes, int|callable $recipients): array
    {
        $parts = SmsParts::count($attributes['message']);

        try {
            $inbox = DB::transaction(function () use ($client, $admin, $attributes, $recipients, $parts) {
                $inbox = Inbox::create($attributes + [
                    'client_id' => $client?->id,
                    'admin_id' => $admin?->id,
                    'mode' => $attributes['mode'] ?? 'send',
                    'parts' => $parts,
                    'aggregator' => config('sms.aggregator'),
                    'is_global' => false,
                    'status' => Inbox::STATUS_QUEUED,
                ]);

                $count = is_callable($recipients) ? (int) $recipients($inbox) : $recipients;
                $units = $count * $parts;

                if ($count === 0) {
                    throw new SmsRejected('There are no recipients to send to.');
                }

                $previous = null;

                if ($client) {
                    $debit = $this->units->debit($client->id, $units, $inbox->id, ucfirst($inbox->type).' SMS to '.$count.' recipient(s)');

                    if (! $debit['ok']) {
                        throw new SmsRejected($debit['reason'], ApiReason::INSUFFICIENT_BALANCE);
                    }

                    $previous = $debit['previous'];
                }

                $inbox->fill([
                    'recipients_count' => $count,
                    'units_used' => $units,
                    'previous_units' => $previous,
                ])->save();

                Outbox::create([
                    'inbox_id' => $inbox->id,
                    'client_id' => $inbox->client_id,
                    'admin_id' => $inbox->admin_id,
                    'request_type' => $inbox->request_type,
                    'type' => $inbox->type,
                    'to' => $inbox->to,
                    'from' => $inbox->from,
                    'message' => $inbox->message,
                    'contact_group_ids' => $inbox->contact_group_ids,
                    'units_used' => $inbox->units_used,
                    'previous_units' => $inbox->previous_units,
                    'aggregator' => $inbox->aggregator,
                    'is_global' => $inbox->is_global,
                    'status' => 'pending',
                ]);

                return $inbox;
            }, 3);
        } catch (SmsRejected $e) {
            return $this->result(true, $e->getMessage(), ApiCode::BAD_REQUEST, $e->reason);
        }

        self::dispatch($inbox);

        if ($client) {
            $this->activity()->record('SMS queued', 'sms', 'created', 'client', $client, $client, [
                'sms_id' => $inbox->id,
                'type' => $inbox->type,
                'recipients' => $inbox->recipients_count,
                'units' => $inbox->units_used,
            ]);
        }

        $data = $admin ? $this->presenter->forAdmin($inbox->fresh()) : $this->presenter->forClient($inbox->fresh());

        return $this->result(false, 'Message queued for sending.', ApiCode::CREATED, ApiReason::CREATED, $data);
    }

    /**
     * Canonical approved sender name, or an error result.
     *
     * @return string|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function clientSender(Client $client, string $from): string|array
    {
        if (SenderIdBlacklist::match($from)) {
            return $this->result(true, 'This sender ID is not allowed.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_ALLOWED);
        }

        $sender = SenderId::query()
            ->where('client_id', $client->id)
            ->where('status', 'approved')
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($from))])
            ->first();

        if (! $sender) {
            return $this->result(true, 'This sender ID is not approved for your account.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_APPROVED);
        }

        return $sender->name;
    }

    /**
     * @param  list<string>  $numbers
     * @return array{valid: list<string>, invalid: list<string>}
     */
    protected function recipients(array $numbers): array
    {
        $valid = [];
        $invalid = [];

        foreach ($numbers as $number) {
            $mobile = GhanaPhone::toMobile((string) $number);

            if ($mobile) {
                $valid[$mobile] = $mobile;
            } else {
                $invalid[] = (string) $number;
            }
        }

        return ['valid' => array_values(array_map('strval', $valid)), 'invalid' => $invalid];
    }
}
