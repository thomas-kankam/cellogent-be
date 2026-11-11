<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\TrackClickRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;

class CampaignController extends Controller
{
    public function __construct(protected CampaignService $campaigns) {}

    /**
     * Link click from a campaign SMS. Phone is optional.
     */
    public function click(TrackClickRequest $request, Campaign $campaign): JsonResponse
    {
        return $this->respondFrom($this->campaigns->click($campaign, $request->validated('phone_number')));
    }
}
