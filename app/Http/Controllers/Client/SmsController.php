<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sms\ReblastSmsRequest;
use App\Http\Requests\Sms\ResendSmsRequest;
use App\Http\Requests\Sms\SendBulkSmsRequest;
use App\Http\Requests\Sms\SendQuickSmsRequest;
use App\Models\Contact;
use App\Models\Inbox;
use App\Models\Sent;
use App\Models\SmsUnitLedger;
use App\Services\Sms\SmsPresenter;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsUnitService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Used by the dashboard (Passport, request_type=system) and the public API
 * (API key, request_type=api). Responses never mention the aggregator.
 */
class SmsController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected SmsUnitService $units,
        protected SmsPresenter $presenter,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        return $this->apiResponse(false, 'SMS balance loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->units->balance($request->user()->id));
    }

    public function unitHistory(Request $request): JsonResponse
    {
        $query = SmsUnitLedger::query()->where('client_id', $request->user()->id)->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return $this->paginatedApiResponse('Unit history loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (SmsUnitLedger $row) => [
            'id' => $row->id,
            'type' => $row->type,
            'units' => (int) $row->units,
            'balance_before' => (int) $row->balance_before,
            'balance_after' => (int) $row->balance_after,
            'sms_id' => $row->inbox_id,
            'description' => $row->description,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ]);
    }

    public function quick(SendQuickSmsRequest $request): JsonResponse
    {
        return $this->respondFrom($this->sms->quick($request->user(), $request->validated(), $this->requestType($request)));
    }

    public function bulk(SendBulkSmsRequest $request): JsonResponse
    {
        return $this->respondFrom($this->sms->bulk($request->user(), $request->validated(), $this->requestType($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $query = Inbox::query()->where('client_id', $request->user()->id)->latest('id');

        foreach (['type', 'status', 'request_type', 'mode'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter));
            }
        }

        if ($request->filled('source')) {
            $sources = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('source')))));

            if ($sources !== []) {
                $query->whereIn(
                    DB::raw("COALESCE(source, CASE WHEN type = 'quick' THEN 'quick_sms' ELSE 'bulk_sms' END)"),
                    $sources,
                );
            }
        }

        if ($request->filled('contact_group_id')) {
            $groupId = $request->integer('contact_group_id');
            $query->whereJsonContains('contact_group_ids', $groupId);
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->date('from_date')->startOfDay());
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->date('to_date')->endOfDay());
        }

        $paginator = $this->paginateQuery($request, $query);
        $this->presenter->warmGroups($paginator->getCollection());
        $this->presenter->warmFailureReasons($paginator->getCollection());

        return $this->paginatedApiResponse(
            'Messages loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Inbox $row) => $this->presenter->forClient($row),
        );
    }

    public function show(Request $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Message loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->presenter->forClientShow($row));
    }

    /**
     * Per-contact delivery report for a quick or bulk SMS.
     * - Single recipient (e.g. 1-contact quick SMS): returns `contact` directly without pagination.
     * - Multiple recipients (multi quick or bulk SMS): returns paginated `contacts` + `pagination`.
     */
    public function deliveryReport(Request $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $stats = $this->deliveryStats($row);

        $query = Sent::query()
            ->where('client_id', $row->client_id)
            ->where('inbox_id', $row->id)
            ->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('to')) {
            $query->where('to', $request->string('to'));
        }

        $paginator = $this->paginateQuery($request, $query, 50);
        $groupMap = $this->presenter->recipientGroupsForInbox($row, $paginator->getCollection());

        $contacts = $paginator->getCollection()->map(function (Sent $sent) use ($row, $groupMap) {
            $presented = $this->presenter->recipientForClient($sent, $row, $groupMap[(string) $sent->to] ?? []);

            return [
                'to' => $presented['to'],
                'status' => $presented['status'],
                'failure_reason' => $presented['failure_reason'],
                'message_id' => $presented['message_id'],
                'units_used' => $presented['units_used'],
                'units_refunded' => $presented['units_refunded'],
                'contact_groups' => $presented['contact_groups'],
                'sent_at' => $presented['sent_at'],
                'delivered_at' => $presented['delivered_at'],
            ];
        })->values()->all();

        $payload = [
            'sms_id' => $row->id,
            'type' => $row->type,
            'from' => $row->from,
            'status' => $row->status,
            'summary' => $stats['summary'],
            'failure_breakdown' => $stats['failure_breakdown'],
        ];

        $isSingleRecipient = $stats['summary']['total_recipients'] <= 1
            && $paginator->total() === 1
            && $paginator->currentPage() === 1
            && ! $request->filled('status')
            && ! $request->filled('to');

        if ($isSingleRecipient) {
            $payload['contact'] = $contacts[0];
        } else {
            $payload['contacts'] = $contacts;
            $payload['pagination'] = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        }

        return $this->apiResponse(false, 'Delivery report loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $payload);
    }

    /**
     * Lightweight sent SMS status check by inbox ID or message_id (works for quick & bulk SMS).
     */
    public function statusByReference(Request $request, string $reference): JsonResponse
    {
        $reference = trim($reference);
        $clientId = (int) $request->user()->id;

        if ($reference === '') {
            return $this->apiResponse(true, 'Provide a valid SMS ID or message_id.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $inbox = null;
        $matchedSent = null;

        if (ctype_digit($reference)) {
            $inbox = Inbox::query()->where('client_id', $clientId)->whereKey((int) $reference)->first();
        }

        if (! $inbox) {
            $inbox = Inbox::query()
                ->where('client_id', $clientId)
                ->where('message_id', $reference)
                ->latest('id')
                ->first();
        }

        if (! $inbox) {
            $matchedSent = Sent::query()
                ->where('client_id', $clientId)
                ->where('message_id', $reference)
                ->first();

            if (! $matchedSent) {
                return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
            }

            $inbox = Inbox::query()
                ->where('client_id', $clientId)
                ->whereKey($matchedSent->inbox_id)
                ->first();

            if (! $inbox) {
                return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
            }
        }

        $stats = $this->deliveryStats($inbox);

        $data = [
            'id' => $inbox->id,
            'type' => $inbox->type,
            'from' => $inbox->from,
            'message_id' => $inbox->message_id,
            'status' => $inbox->status,
            'scheduled_at' => optional($inbox->scheduled_at)->toDateTimeString(),
            'created_at' => optional($inbox->created_at)->toDateTimeString(),
            'completed_at' => optional($inbox->completed_at)->toDateTimeString(),
            'counts' => $stats['summary'],
            'units' => $stats['units'],
        ];

        if ($matchedSent) {
            $presented = $this->presenter->recipientForClient($matchedSent, $inbox);
            $data['recipient'] = [
                'to' => $presented['to'],
                'status' => $presented['status'],
                'failure_reason' => $presented['failure_reason'],
                'sent_at' => $presented['sent_at'],
                'delivered_at' => $presented['delivered_at'],
            ];
        }

        return $this->apiResponse(false, 'SMS status loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $data);
    }

    public function recipients(Request $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $query = Sent::query()->where('inbox_id', $row->id)->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('to')) {
            $query->where('to', $request->string('to'));
        }

        // Limit recipients to those who belong to one of the blast's groups.
        if ($request->filled('contact_group_id') && ($row->contact_group_ids ?? []) !== []) {
            $groupId = $request->integer('contact_group_id');

            if (in_array($groupId, array_map('intval', $row->contact_group_ids), true)) {
                $phones = Contact::query()
                    ->where('client_id', $row->client_id)
                    ->where('contact_group_id', $groupId)
                    ->pluck('phone_number');

                $query->whereIn('to', $phones);
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        $paginator = $this->paginateQuery($request, $query, 50);
        $groupMap = $this->presenter->recipientGroupsForInbox($row, $paginator->getCollection());

        return $this->paginatedApiResponse(
            'Recipients loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Sent $sent) => $this->presenter->recipientForClient(
                $sent,
                $row,
                $groupMap[(string) $sent->to] ?? [],
            ),
        );
    }

    public function reblast(ReblastSmsRequest $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->sms->reblast($request->user(), $row, $request->validated(), $this->requestType($request)));
    }

    public function resend(ResendSmsRequest $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->sms->resend($request->user(), $row, $request->validated(), $this->requestType($request)));
    }

    public function cancel(Request $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->sms->cancelScheduled($request->user(), $row));
    }

    /**
     * @return array{summary: array<string, int|float>, failure_breakdown: array<string, int>, units: array<string, int>}
     */
    protected function deliveryStats(Inbox $row): array
    {
        $statusCounts = Sent::query()
            ->where('client_id', $row->client_id)
            ->where('inbox_id', $row->id)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $failedReasonsRaw = Sent::query()
            ->where('client_id', $row->client_id)
            ->where('inbox_id', $row->id)
            ->where('status', Sent::FAILED)
            ->select(DB::raw('UPPER(COALESCE(dlr_status, "")) as dlr'), DB::raw('COUNT(*) as total'))
            ->groupBy('dlr')
            ->pluck('total', 'dlr')
            ->map(fn ($v) => (int) $v)
            ->all();

        $undelivered = $failedReasonsRaw['UNDELIVERED'] ?? 0;
        $rejected = $failedReasonsRaw['REJECTED'] ?? 0;
        $expired = $failedReasonsRaw['EXPIRED'] ?? 0;
        $failedTotal = $statusCounts[Sent::FAILED] ?? (int) $row->failed_count;
        $notSent = max(0, $failedTotal - $undelivered - $rejected - $expired);

        $queued = $statusCounts[Sent::QUEUED] ?? 0;
        $submitted = $statusCounts[Sent::SUBMITTED] ?? 0;
        $delivered = $statusCounts[Sent::DELIVERED] ?? (int) $row->delivered_count;
        $totalRecipients = max((int) $row->recipients_count, $queued + $submitted + $delivered + $failedTotal);
        $pending = $row->status === Inbox::STATUS_CANCELLED
            ? 0
            : max(0, $totalRecipients - $delivered - $failedTotal);
        $deliveryRate = $totalRecipients > 0 ? round(($delivered / $totalRecipients) * 100, 2) : 0.0;

        return [
            'summary' => [
                'total_recipients' => $totalRecipients,
                'queued' => $queued,
                'submitted' => $submitted,
                'delivered' => $delivered,
                'failed' => $failedTotal,
                'pending' => $pending,
                'delivery_rate' => $deliveryRate,
            ],
            'failure_breakdown' => [
                'undelivered' => $undelivered,
                'rejected' => $rejected,
                'expired' => $expired,
                'not_sent' => $notSent,
            ],
            'units' => [
                'charged' => (int) $row->units_used,
                'refunded' => (int) $row->units_refunded,
                'net' => max(0, (int) $row->units_used - (int) $row->units_refunded),
            ],
        ];
    }

    protected function owned(Request $request, int $id): ?Inbox
    {
        return Inbox::query()->where('client_id', $request->user()->id)->whereKey($id)->first();
    }

    protected function requestType(Request $request): string
    {
        return $request->attributes->has('api_key') ? 'api' : 'system';
    }
}
