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
use App\Support\ContactPlaceholders;
use App\Support\GhanaPhone;
use App\Support\SmsParts;
use Carbon\Carbon;
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

        $scheduledAt = $this->resolveScheduledAt($input);
        $requiredUnits = count($recipients['valid']) * SmsParts::count($input['message']);
        $check = $this->units->checkAvailability(
            $client->id,
            $requiredUnits,
            $scheduledAt ? 'schedule this quick SMS' : 'send this quick SMS',
        );

        if (! $check['ok']) {
            return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'required_units' => $check['required_units'],
                'available_units' => $check['available_units'],
                'shortfall_units' => $check['shortfall_units'],
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
                'scheduled_at' => $scheduledAt,
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

        $message = $input['message'];

        $audience = (int) $this->audience($client->id, $groupIds, $message)->distinct()->count('phone_number');

        if ($audience === 0) {
            $error = ContactPlaceholders::hasContactPlaceholders($message)
                ? 'No contacts in the selected groups have all required placeholder fields ('.implode(', ', ContactPlaceholders::requiredPlaceholders($message)).').'
                : 'These contact groups have no contacts that can receive SMS.';

            return $this->result(true, $error, ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $scheduledAt = $this->resolveScheduledAt($input);
        $requiredUnits = $audience * SmsParts::count($message);
        $check = $this->units->checkAvailability(
            $client->id,
            $requiredUnits,
            $scheduledAt ? 'schedule this bulk SMS' : 'send this bulk SMS',
        );

        if (! $check['ok']) {
            return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'required_units' => $check['required_units'],
                'available_units' => $check['available_units'],
                'shortfall_units' => $check['shortfall_units'],
            ]);
        }

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => 'bulk',
                'request_type' => $requestType,
                'from' => $sender,
                'contact_group_ids' => $groupIds,
                'message' => $message,
                'scheduled_at' => $scheduledAt,
            ],
            recipients: $audience,
        );
    }

    /**
     * Re-blast an existing SMS either to its failed recipients (`target=failed`, default)
     * or to all original recipients / contact groups (`target=all`), optionally scheduled.
     *
     * @param  array<string, mixed>|string|null  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function reblast(Client $client, Inbox $source, array|string|null $input, string $requestType): array
    {
        if ((int) $source->client_id !== (int) $client->id) {
            return $this->result(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($source->status === Inbox::STATUS_SCHEDULED) {
            return $this->result(true, 'This message is scheduled and has not been sent yet.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($source->status === Inbox::STATUS_CANCELLED) {
            return $this->result(true, 'Cancelled messages cannot be re-blasted.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if (in_array($source->status, [Inbox::STATUS_QUEUED, Inbox::STATUS_PROCESSING], true)) {
            return $this->result(true, 'This message is still sending. Try again when it finishes.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $message = is_array($input) ? ($input['message'] ?? null) : $input;
        $target = is_array($input) ? strtolower((string) ($input['target'] ?? 'failed')) : 'failed';
        $scheduledAt = is_array($input) ? $this->resolveScheduledAt($input) : null;
        $textToSend = ($message !== null && $message !== '') ? (string) $message : $source->message;
        $requestedFrom = is_array($input) && trim((string) ($input['from'] ?? '')) !== ''
            ? trim((string) $input['from'])
            : $source->from;

        $sender = $this->clientSender($client, $requestedFrom);

        if (is_array($sender)) {
            return $sender;
        }

        if ($target === 'all') {
            return $this->reblastAll($client, $source, $sender, $textToSend, $requestType, $scheduledAt);
        }

        $failed = Sent::query()
            ->where('client_id', $client->id)
            ->where('inbox_id', $source->id)
            ->where('status', Sent::FAILED)
            ->whereNull('retry_inbox_id')
            ->count();

        if ($failed === 0) {
            return $this->result(true, 'There are no failed recipients to send to.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $requiredUnits = $failed * SmsParts::count($textToSend);
        $check = $this->units->checkAvailability(
            $client->id,
            $requiredUnits,
            $scheduledAt ? 'schedule this re-blast' : 're-blast this SMS',
        );

        if (! $check['ok']) {
            return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'required_units' => $check['required_units'],
                'available_units' => $check['available_units'],
                'shortfall_units' => $check['shortfall_units'],
            ]);
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
                'contact_group_ids' => $source->contact_group_ids,
                'message' => $textToSend,
                'scheduled_at' => $scheduledAt,
            ],
            recipients: function (Inbox $inbox) use ($client, $source, $useQuick) {
                $claimed = Sent::query()
                    ->where('client_id', $client->id)
                    ->where('inbox_id', $source->id)
                    ->where('status', Sent::FAILED)
                    ->whereNull('retry_inbox_id')
                    ->update(['retry_inbox_id' => $inbox->id]);

                if ($useQuick) {
                    $inbox->to = Sent::query()
                        ->where('client_id', $client->id)
                        ->where('retry_inbox_id', $inbox->id)
                        ->pluck('to')
                        ->implode(',');
                }

                return $claimed;
            },
        );
    }

    /**
     * Re-blast to all original recipients (quick) or all eligible contacts in the same contact groups (bulk).
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function reblastAll(
        Client $client,
        Inbox $source,
        string $sender,
        string $textToSend,
        string $requestType,
        ?Carbon $scheduledAt,
    ): array {
        if ($source->isQuick()) {
            $recipients = $source->recipients();

            if ($recipients === []) {
                $recipients = Sent::query()
                    ->where('client_id', $client->id)
                    ->where('inbox_id', $source->id)
                    ->pluck('to')
                    ->map(fn ($to) => (string) $to)
                    ->unique()
                    ->values()
                    ->all();
            }

            if ($recipients === []) {
                return $this->result(true, 'This message has no recipients to re-blast.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
            }

            $requiredUnits = count($recipients) * SmsParts::count($textToSend);
            $check = $this->units->checkAvailability(
                $client->id,
                $requiredUnits,
                $scheduledAt ? 'schedule this re-blast' : 're-blast this SMS',
            );

            if (! $check['ok']) {
                return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                    'required_units' => $check['required_units'],
                    'available_units' => $check['available_units'],
                    'shortfall_units' => $check['shortfall_units'],
                ]);
            }

            return $this->queue(
                client: $client,
                admin: null,
                attributes: [
                    'type' => 'quick',
                    'request_type' => $requestType,
                    'mode' => 'reblast_all',
                    'parent_id' => $source->id,
                    'from' => $sender,
                    'to' => implode(',', $recipients),
                    'message' => $textToSend,
                    'scheduled_at' => $scheduledAt,
                ],
                recipients: count($recipients),
            );
        }

        $groupIds = array_values(array_unique(array_map('intval', $source->contact_group_ids ?? [])));

        if ($groupIds === []) {
            return $this->result(true, 'This bulk message has no contact groups to re-blast.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $owned = ContactGroup::query()
            ->where('client_id', $client->id)
            ->whereIn('id', $groupIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($owned === []) {
            return $this->result(true, 'The original contact groups for this message are no longer available.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $audience = (int) $this->audience($client->id, $owned, $textToSend)->distinct()->count('phone_number');

        if ($audience === 0) {
            $error = ContactPlaceholders::hasContactPlaceholders($textToSend)
                ? 'No contacts in the selected groups have all required placeholder fields ('.implode(', ', ContactPlaceholders::requiredPlaceholders($textToSend)).').'
                : 'These contact groups have no contacts that can receive SMS.';

            return $this->result(true, $error, ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $requiredUnits = $audience * SmsParts::count($textToSend);
        $check = $this->units->checkAvailability(
            $client->id,
            $requiredUnits,
            $scheduledAt ? 'schedule this bulk re-blast' : 're-blast this bulk SMS',
        );

        if (! $check['ok']) {
            return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'required_units' => $check['required_units'],
                'available_units' => $check['available_units'],
                'shortfall_units' => $check['shortfall_units'],
            ]);
        }

        return $this->queue(
            client: $client,
            admin: null,
            attributes: [
                'type' => 'bulk',
                'request_type' => $requestType,
                'mode' => 'reblast_all',
                'parent_id' => $source->id,
                'from' => $sender,
                'contact_group_ids' => $owned,
                'message' => $textToSend,
                'scheduled_at' => $scheduledAt,
            ],
            recipients: $audience,
        );
    }

    /**
     * Resend a quick message with new text using the aggregator's resend (same recipients),
     * optionally scheduled for later.
     *
     * @param  array<string, mixed>|string  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function resend(Client $client, Inbox $source, array|string $input, string $requestType): array
    {
        if ((int) $source->client_id !== (int) $client->id) {
            return $this->result(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if (! $source->isQuick()) {
            return $this->result(true, 'Only quick messages can be resent. Use re-blast for bulk messages.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($source->status === Inbox::STATUS_SCHEDULED) {
            return $this->result(true, 'This message is scheduled and has not been sent yet.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($source->status === Inbox::STATUS_CANCELLED) {
            return $this->result(true, 'Cancelled messages cannot be resent.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if (in_array($source->status, [Inbox::STATUS_QUEUED, Inbox::STATUS_PROCESSING], true)) {
            return $this->result(true, 'This message is still sending. Try again when it finishes.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $message = is_array($input) ? (string) ($input['message'] ?? '') : $input;
        $scheduledAt = is_array($input) ? $this->resolveScheduledAt($input) : null;

        $recipients = Sent::query()
            ->where('client_id', $client->id)
            ->where('inbox_id', $source->id)
            ->pluck('to')
            ->map(fn ($to) => (string) $to)
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            $recipients = $source->recipients();
        }

        if ($recipients === []) {
            return $this->result(true, 'This message has nothing that can be resent.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $requestedFrom = is_array($input) && trim((string) ($input['from'] ?? '')) !== ''
            ? trim((string) $input['from'])
            : $source->from;

        $sender = $this->clientSender($client, $requestedFrom);

        if (is_array($sender)) {
            return $sender;
        }

        $requiredUnits = count($recipients) * SmsParts::count($message);
        $check = $this->units->checkAvailability(
            $client->id,
            $requiredUnits,
            $scheduledAt ? 'schedule this SMS resend' : 'resend this SMS',
        );

        if (! $check['ok']) {
            return $this->result(true, $check['reason'], ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                'required_units' => $check['required_units'],
                'available_units' => $check['available_units'],
                'shortfall_units' => $check['shortfall_units'],
            ]);
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
                'scheduled_at' => $scheduledAt,
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
     * Contacts in the groups that can receive SMS (not DND, not in an upload being deleted,
     * and having every contact placeholder referenced in `$template` populated).
     *
     * @param  list<int>  $groupIds
     */
    public function audience(int $clientId, array $groupIds, ?string $template = null): Builder
    {
        $query = Contact::query()
            ->visible()
            ->where('client_id', $clientId)
            ->whereIn('contact_group_id', $groupIds)
            ->where(fn ($q) => $q->whereNull('dnd_status')->orWhere('dnd_status', '!=', 'active'));

        if ($template !== null && ContactPlaceholders::hasContactPlaceholders($template)) {
            ContactPlaceholders::applyRequiredFilter($query, $template);
        }

        return $query;
    }

    /**
     * Cancel a scheduled quick or bulk SMS before it begins sending and refund its reserved units.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function cancelScheduled(Client $client, Inbox $source): array
    {
        if ((int) $source->client_id !== (int) $client->id) {
            return $this->result(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $cancelled = DB::transaction(function () use ($source): ?Inbox {
            $locked = Inbox::query()->whereKey($source->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Inbox::STATUS_SCHEDULED) {
                return null;
            }

            $locked->update(['status' => Inbox::STATUS_CANCELLED]);

            return $locked;
        }, 3);

        if (! $cancelled) {
            return $this->result(
                true,
                'Only scheduled messages that have not started sending can be cancelled.',
                ApiCode::BAD_REQUEST,
                ApiReason::INVALID,
            );
        }

        if ((int) $cancelled->units_used > 0) {
            $this->units->refund(
                $client->id,
                (int) $cancelled->units_used,
                $cancelled->id,
                'Cancelled scheduled '.ucfirst($cancelled->type).' SMS',
            );
        }

        $this->activity()->record('Scheduled SMS cancelled', 'sms', 'updated', 'client', $client, $client, [
            'sms_id' => $cancelled->id,
            'type' => $cancelled->type,
            'units_refunded' => (int) $cancelled->units_used,
            'scheduled_at' => optional($cancelled->scheduled_at)->toDateTimeString(),
        ]);

        return $this->result(
            false,
            'Scheduled message cancelled and SMS units refunded.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->presenter->forClient($cancelled->fresh()),
        );
    }

    /**
     * Find scheduled quick/bulk SMS whose scheduled_at has arrived, move them to queued,
     * create their Outbox rows, and dispatch their queue jobs.
     */
    public function dispatchScheduled(int $limit = 200): int
    {
        $ids = Inbox::query()
            ->where('status', Inbox::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $dispatched = 0;

        foreach ($ids as $id) {
            $claimed = DB::transaction(function () use ($id): ?Inbox {
                $inbox = Inbox::query()->whereKey($id)->lockForUpdate()->first();

                if (! $inbox || $inbox->status !== Inbox::STATUS_SCHEDULED) {
                    return null;
                }

                if ($inbox->scheduled_at === null || $inbox->scheduled_at->isFuture()) {
                    return null;
                }

                $inbox->update(['status' => Inbox::STATUS_QUEUED]);
                $this->createOutbox($inbox);

                return $inbox;
            }, 3);

            if ($claimed) {
                self::dispatch($claimed);
                $dispatched++;
            }
        }

        return $dispatched;
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
     * Create inbox + outbox, take units, then queue the job (or hold until scheduled_at).
     *
     * @param  array<string, mixed>  $attributes
     * @param  int|callable(Inbox): int  $recipients
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function queue(?Client $client, ?Admin $admin, array $attributes, int|callable $recipients): array
    {
        $parts = SmsParts::count($attributes['message']);
        $scheduledAt = $attributes['scheduled_at'] ?? null;
        $isScheduled = $scheduledAt instanceof Carbon && $scheduledAt->isFuture();

        try {
            $inbox = DB::transaction(function () use ($client, $admin, $attributes, $recipients, $parts, $scheduledAt, $isScheduled) {
                $inbox = Inbox::create($attributes + [
                    'client_id' => $client?->id,
                    'admin_id' => $admin?->id,
                    'mode' => $attributes['mode'] ?? 'send',
                    'parts' => $parts,
                    'aggregator' => config('sms.aggregator'),
                    'is_global' => false,
                    'status' => $isScheduled ? Inbox::STATUS_SCHEDULED : Inbox::STATUS_QUEUED,
                    'scheduled_at' => $isScheduled ? $scheduledAt : null,
                ]);

                $count = is_callable($recipients) ? (int) $recipients($inbox) : $recipients;
                $units = $count * $parts;

                if ($count === 0) {
                    throw new SmsRejected('There are no recipients to send to.');
                }

                $previous = null;

                if ($client) {
                    $label = ($isScheduled ? 'Scheduled ' : '').ucfirst($inbox->type).' SMS to '.$count.' recipient(s)';
                    $debit = $this->units->debit($client->id, $units, $inbox->id, $label);

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

                if (! $isScheduled) {
                    $this->createOutbox($inbox);
                }

                return $inbox;
            }, 3);
        } catch (SmsRejected $e) {
            return $this->result(true, $e->getMessage(), ApiCode::BAD_REQUEST, $e->reason);
        }

        if (! $isScheduled) {
            self::dispatch($inbox);
        }

        $actionTitle = $isScheduled ? 'SMS scheduled' : 'SMS queued';
        $meta = [
            'sms_id' => $inbox->id,
            'type' => $inbox->type,
            'recipients' => $inbox->recipients_count,
            'units' => $inbox->units_used,
        ];

        if ($isScheduled) {
            $meta['scheduled_at'] = $inbox->scheduled_at?->toDateTimeString();
        }

        if ($client) {
            $this->activity()->record($actionTitle, 'sms', 'created', 'client', $client, $client, $meta);
        } elseif ($admin) {
            $this->activity()->record('Admin '.$actionTitle, 'sms', 'created', 'admin', $admin, null, $meta);
        }

        $data = $admin ? $this->presenter->forAdmin($inbox->fresh()) : $this->presenter->forClient($inbox->fresh());
        $responseMessage = $isScheduled
            ? 'Message scheduled for '.$inbox->scheduled_at->toDateTimeString().'.'
            : 'Message queued for sending.';

        return $this->result(false, $responseMessage, ApiCode::CREATED, ApiReason::CREATED, $data);
    }

    protected function createOutbox(Inbox $inbox): void
    {
        if (Outbox::query()->where('inbox_id', $inbox->id)->exists()) {
            return;
        }

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
    }

    /**
     * Resolve an optional schedule datetime from validated input. Returns null for immediate sends.
     *
     * @param  array<string, mixed>  $input
     */
    protected function resolveScheduledAt(array $input): ?Carbon
    {
        if (($input['send_mode'] ?? null) === 'now') {
            return null;
        }

        $raw = $input['scheduled_at'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        return Carbon::parse((string) $raw);
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
