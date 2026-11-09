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

        return $this->respondFrom($this->sms->reblast($request->user(), $row, $request->input('message'), $this->requestType($request)));
    }

    public function resend(ResendSmsRequest $request, int $inbox): JsonResponse
    {
        $row = $this->owned($request, $inbox);

        if (! $row) {
            return $this->apiResponse(true, 'Message not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->sms->resend($request->user(), $row, $request->string('message')->toString(), $this->requestType($request)));
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
