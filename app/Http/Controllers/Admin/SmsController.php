<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sms\SendQuickSmsRequest;
use App\Models\AggregatorRequest;
use App\Models\Inbox;
use App\Models\Sent;
use App\Services\Sms\AggregatorBalanceService;
use App\Services\Sms\KairosClient;
use App\Services\Sms\SmsPresenter;
use App\Services\Sms\SmsService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only SMS view: our records plus aggregator details.
 */
class SmsController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected SmsPresenter $presenter,
        protected AggregatorBalanceService $balances,
        protected KairosClient $kairos,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->respondFrom($this->balances->overview());
    }

    public function aggregatorBalance(): JsonResponse
    {
        return $this->respondFrom($this->balances->refresh());
    }

    public function aggregatorRequests(Request $request): JsonResponse
    {
        $query = AggregatorRequest::query()->latest('id');

        foreach (['endpoint', 'inbox_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->has('ok')) {
            $query->where('ok', $request->boolean('ok'));
        }

        return $this->paginatedApiResponse('Aggregator requests loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (AggregatorRequest $row) => [
            'id' => $row->id,
            'aggregator' => $row->aggregator,
            'endpoint' => $row->endpoint,
            'inbox_id' => $row->inbox_id,
            'message_count' => $row->message_count,
            'http_status' => $row->http_status,
            'ok' => $row->ok,
            'status_message' => $row->status_message,
            'transaction_id' => $row->transaction_id,
            'sequence_no' => $row->sequence_no,
            'duration_ms' => $row->duration_ms,
            'response' => $row->response,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ]);
    }

    public function deliveryReceipt(string $sequenceNo): JsonResponse
    {
        $response = $this->kairos->deliveryReceipt($sequenceNo);

        if (! $response->ok) {
            return $this->apiResponse(true, 'Delivery receipt not available: '.$response->error, ApiCode::NOT_FOUND, ApiReason::NOT_FOUND, $response->body);
        }

        return $this->apiResponse(false, 'Delivery receipt loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $response->body);
    }

    public function quick(SendQuickSmsRequest $request): JsonResponse
    {
        return $this->respondFrom($this->sms->adminQuick($request->user(), $request->validated()));
    }

    public function index(Request $request): JsonResponse
    {
        $query = Inbox::query()->latest('id');

        foreach (['client_id', 'admin_id', 'type', 'status', 'request_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->date('from_date')->startOfDay());
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->date('to_date')->endOfDay());
        }

        $paginator = $this->paginateQuery($request, $query);
        $this->presenter->warmGroups($paginator->getCollection());

        return $this->paginatedApiResponse('Messages loaded.', ApiReason::SUCCESS, $paginator, fn (Inbox $row) => $this->presenter->forAdmin($row));
    }

    public function show(int $inbox): JsonResponse
    {
        $row = Inbox::query()->find($inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $this->presenter->warmGroups([$row]);

        return $this->apiResponse(false, 'Message loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->presenter->forAdmin($row));
    }

    public function recipients(Request $request, int $inbox): JsonResponse
    {
        $row = Inbox::query()->find($inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $query = Sent::query()->where('inbox_id', $inbox)->orderBy('id');

        foreach (['status', 'to', 'sequence_no'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $paginator = $this->paginateQuery($request, $query, 50);
        $groupMap = $this->presenter->recipientGroupsForInbox($row, $paginator->getCollection());

        return $this->paginatedApiResponse(
            'Recipients loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Sent $sent) => $this->presenter->recipientForAdmin(
                $sent,
                $row,
                $groupMap[(string) $sent->to] ?? [],
            ),
        );
    }

    /**
     * Platform messages (OTP etc.) that are not tied to an inbox.
     */
    public function systemMessages(Request $request): JsonResponse
    {
        $query = Sent::query()->whereNull('inbox_id')->latest('id');

        foreach (['status', 'to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $this->paginatedApiResponse('System messages loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query, 50), fn (Sent $sent) => $this->presenter->recipientForAdmin($sent) + [
            'from' => $sent->from,
            'message' => $sent->message,
        ]);
    }
}
