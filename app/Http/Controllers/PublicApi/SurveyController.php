<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\TrackClickRequest;
use App\Http\Requests\Survey\SubmitSurveyRequest;
use App\Services\SurveyService;
use Illuminate\Http\JsonResponse;

/**
 * Respondent-facing survey endpoints. The unguessable token is the only credential.
 */
class SurveyController extends Controller
{
    public function __construct(protected SurveyService $surveys) {}

    public function show(string $token): JsonResponse
    {
        return $this->respondFrom($this->surveys->publicShow($token));
    }

    public function click(TrackClickRequest $request, string $token): JsonResponse
    {
        return $this->respondFrom($this->surveys->click($token, $request->validated('phone_number')));
    }

    public function submit(SubmitSurveyRequest $request, string $token): JsonResponse
    {
        return $this->respondFrom($this->surveys->submit($token, $request->validated('phone_number'), $request->validated('answers')));
    }
}
