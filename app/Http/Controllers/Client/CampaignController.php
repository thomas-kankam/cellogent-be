<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\RecordCampaignEventRequest;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Http\Requests\Engagement\FundRequest;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Services\CampaignService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(protected CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->campaigns->index($request->user(), $request->only(['status', 'type', 'search']));

        return $this->paginatedApiResponse('Campaigns loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (Campaign $campaign) => $this->campaigns->present($campaign));
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->show($campaign));
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        return $this->respondFrom($this->campaigns->create($request->user(), $request->validated()));
    }

    public function update(StoreCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->update($request->user(), $campaign, $request->validated()));
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->delete($request->user(), $campaign));
    }

    public function fund(FundRequest $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->fund($request->user(), $campaign, $request->validated()));
    }

    public function activate(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->activate($request->user(), $campaign));
    }

    public function pause(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->pause($request->user(), $campaign));
    }

    public function resume(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->resume($request->user(), $campaign));
    }

    public function complete(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->complete($request->user(), $campaign));
    }

    public function cancel(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->cancel($request->user(), $campaign));
    }

    public function analytics(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->owned($request, $campaign) ?? $this->respondFrom($this->campaigns->analytics($campaign));
    }

    public function events(Request $request, Campaign $campaign): JsonResponse
    {
        if ($response = $this->owned($request, $campaign)) {
            return $response;
        }

        $query = CampaignEvent::query()->where('campaign_id', $campaign->id)->latest('id');

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        return $this->paginatedApiResponse('Campaign events loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query, 50), fn (CampaignEvent $event) => [
            'id' => $event->id,
            'campaign_id' => $event->campaign_id,
            'contact_id' => $event->contact_id,
            'phone' => $event->phone,
            'event' => $event->event,
            'meta' => $event->meta,
            'created_at' => optional($event->created_at)->toDateTimeString(),
        ]);
    }

    public function recordEvent(RecordCampaignEventRequest $request, Campaign $campaign): JsonResponse
    {
        if ($response = $this->owned($request, $campaign)) {
            return $response;
        }

        return $this->respondFrom($this->campaigns->recordEvent(
            $campaign,
            $request->validated('event'),
            $request->validated('phone_number'),
            (array) $request->validated('meta', []),
        ));
    }

    protected function owned(Request $request, Campaign $campaign): ?JsonResponse
    {
        if ((int) $campaign->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Campaign not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return null;
    }
}
