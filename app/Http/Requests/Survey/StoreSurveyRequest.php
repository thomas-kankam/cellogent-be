<?php

namespace App\Http\Requests\Survey;

use App\Http\Requests\ApiFormRequest;
use App\Models\Survey;
use App\Services\SurveyService;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT). On update every field is optional; sending `questions`
 * replaces the question list (questions that carry an existing id are kept and updated).
 */
class StoreSurveyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $update = $this->route('survey') instanceof Survey;
        $required = $update ? 'sometimes' : 'required';
        $maxGroups = (int) config('sms.bulk.max_groups', 20);

        return [
            'name' => [$required, 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
            'contact_group_ids' => [$required, 'array', 'min:1', "max:{$maxGroups}"],
            'contact_group_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'sender' => [$required, 'string', 'min:3', 'max:11'],
            'send_at' => ['nullable', 'date'],
            'reward_enabled' => ['nullable', 'boolean'],
            'reward_id' => ['nullable', 'integer', 'min:1'],
            'reward' => ['nullable', 'array'],
            'reward.enabled' => ['nullable', 'boolean'],
            'reward.reward_id' => ['nullable', 'integer', 'min:1'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'questions' => [$required, 'array', 'min:1', 'max:'.SurveyService::MAX_QUESTIONS],
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
