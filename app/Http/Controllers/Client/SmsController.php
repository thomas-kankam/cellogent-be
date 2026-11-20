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

        $this->presenter->warmGroups([$row]);

        return $this->apiResponse(false, 'Message loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->presenter->forClient($row));
    }

    /**
     * Full delivery report (summary, failure breakdown, units & paginated per-recipient status)
     * for a quick or bulk SMS. Available on both dashboard and /api/v1.
     */
    public function deliveryReport(Request $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->buildDeliveryReportResponse($request, $row);
    }

    /**
     * Check delivery status by either inbox ID or message_id (works for quick & bulk SMS).
     */
    public function statusByReference(Request $request, string $reference): JsonResponse
    {
        $reference = trim($reference);
        $clientId = (int) $request->user()->id;

        if ($reference === '') {
            return $this->apiResponse(true, 'Provide a valid SMS ID or message_id.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $inbox = null;

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

        $matchedSentQuery = null;

        if (! $inbox) {
            $sent = Sent::query()
                ->where('client_id', $clientId)
                ->where('message_id', $reference)
                ->first();

            if (! $sent) {
                return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
            }

            $inbox = Inbox::query()
                ->where('client_id', $clientId)
                ->whereKey($sent->inbox_id)
                ->first();

            if (! $inbox) {
                return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
            }

            $matchedSentQuery = Sent::query()
                ->where('client_id', $clientId)
                ->where('inbox_id', $inbox->id)
                ->where('message_id', $reference)
                ->orderBy('id');
        }

        return $this->buildDeliveryReportResponse($request, $inbox, $matchedSentQuery);
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

    protected function buildDeliveryReportResponse(Request $request, Inbox $row, ?\Illuminate\Database\Eloquent\Builder $recipientQuery = null): JsonResponse
    {
        $this->presenter->warmGroups([$row]);

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

        $query = $recipientQuery ?? Sent::query()
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

        $recipients = $paginator->getCollection()->map(fn (Sent $sent) => $this->presenter->recipientForClient(
            $sent,
            $row,
            $groupMap[(string) $sent->to] ?? [],
        ))->values()->all();

        return $this->apiResponse(false, 'Delivery report loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'sms' => $this->presenter->forClient($row),
            'report' => [
                'status' => $row->status,
                'total_recipients' => $totalRecipients,
                'queued' => $queued,
                'submitted' => $submitted,
                'delivered' => $delivered,
                'failed' => $failedTotal,
                'pending' => $pending,
                'delivery_rate' => $deliveryRate,
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
            ],
            'recipients' => [
                'items' => $recipients,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
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
