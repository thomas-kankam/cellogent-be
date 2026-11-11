<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\FundRequest;
use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\SyncSurveyQuestionsRequest;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\SurveyService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    public function __construct(protected SurveyService $surveys) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->surveys->index($request->user(), $request->only(['status', 'search']));

        return $this->paginatedApiResponse('Surveys loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (Survey $survey) => $this->surveys->present($survey));
    }

    public function show(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->show($survey));
    }

    public function store(StoreSurveyRequest $request): JsonResponse
    {
        return $this->respondFrom($this->surveys->create($request->user(), $request->validated()));
    }

    public function update(StoreSurveyRequest $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->update($request->user(), $survey, $request->validated()));
    }

    public function destroy(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->delete($request->user(), $survey));
    }

    public function questions(Request $request, Survey $survey): JsonResponse
    {
        if ($response = $this->owned($request, $survey)) {
            return $response;
        }

        return $this->apiResponse(false, 'Survey questions loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $survey->questions->map(fn (SurveyQuestion $question) => $this->surveys->presentQuestion($question))->values()->all());
    }

    /**
     * Replace the question list (same shape and rules as `questions` on create/update).
     */
    public function syncQuestions(SyncSurveyQuestionsRequest $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->update($request->user(), $survey, ['questions' => $request->validated('questions')]));
    }

    public function fund(FundRequest $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->fund($request->user(), $survey, $request->validated()));
    }

    public function activate(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->activate($request->user(), $survey));
    }

    public function pause(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->pause($request->user(), $survey));
    }

    public function resume(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->resume($request->user(), $survey));
    }

    public function complete(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->complete($request->user(), $survey));
    }

    public function cancel(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->cancel($request->user(), $survey));
    }

    public function analytics(Request $request, Survey $survey): JsonResponse
    {
        return $this->owned($request, $survey) ?? $this->respondFrom($this->surveys->analytics($survey));
    }

    /**
     * Who clicked / answered (owner only).
     */
    public function responses(Request $request, Survey $survey): JsonResponse
    {
        if ($response = $this->owned($request, $survey)) {
            return $response;
        }

        $query = SurveyResponse::query()->where('survey_id', $survey->id)->latest('id');

        if ($request->boolean('responded')) {
            $query->whereNotNull('responded_at');
        }

        return $this->paginatedApiResponse('Survey responses loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query, 50), fn (SurveyResponse $row) => [
            'id' => $row->id,
            'contact_id' => $row->contact_id,
            'phone' => $row->phone,
            'clicked_at' => optional($row->clicked_at)->toDateTimeString(),
            'responded_at' => optional($row->responded_at)->toDateTimeString(),
            'answers' => $row->answers,
            'reward_status' => $row->reward_status,
        ]);
    }

    protected function owned(Request $request, Survey $survey): ?JsonResponse
    {
        if ((int) $survey->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Survey not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return null;
    }
}
