<?php

namespace App\Http\Requests\Survey;

use App\Http\Requests\ApiFormRequest;
use App\Models\Survey;
use App\Services\SurveyService;
use Illuminate\Validation\Rule;

class SyncSurveyQuestionsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'questions' => ['required', 'array', 'min:1', 'max:'.SurveyService::MAX_QUESTIONS],
            'questions.*.id' => ['nullable', 'integer', 'min:1'],
            'questions.*.prompt' => ['required', 'string', 'max:500'],
            'questions.*.type' => ['required', Rule::in(Survey::QUESTION_TYPES)],
            'questions.*.options' => ['nullable', 'array', 'max:20'],
            'questions.*.options.*' => ['string', 'max:150'],
            'questions.*.required' => ['nullable', 'boolean'],
            'questions.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
