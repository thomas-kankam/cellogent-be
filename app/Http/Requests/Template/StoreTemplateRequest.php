<?php

namespace App\Http\Requests\Template;

use App\Http\Requests\ApiFormRequest;
use App\Models\Survey;
use App\Models\Template;
use App\Services\CampaignService;
use App\Services\SurveyService;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $update = $this->route('template') instanceof Template;
        $required = $update ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, 'string', Rule::in(Template::TYPES)],
            'occasion_type' => ['nullable', 'string', Rule::in(CampaignService::OCCASION_TYPES)],
            'category' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'body' => [$required, 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
            'cta' => ['nullable', 'array'],
            'cta.enabled' => ['nullable', 'boolean'],
            'cta.type' => ['nullable', 'string', 'in:link,ussd,phone'],
            'cta.value' => ['nullable', 'string', 'max:500'],
            'cta.url' => ['nullable', 'url', 'max:500'],
            'cta.label' => ['nullable', 'string', 'max:60'],
            'questions' => ['nullable', 'array', 'max:'.SurveyService::MAX_QUESTIONS],
            'questions.*.prompt' => ['required_with:questions', 'string', 'max:500'],
            'questions.*.type' => ['required_with:questions', Rule::in(Survey::QUESTION_TYPES)],
            'questions.*.options' => ['nullable', 'array', 'max:20'],
            'questions.*.options.*' => ['string', 'max:150'],
            'questions.*.required' => ['nullable', 'boolean'],
            'questions.*.position' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', Rule::in(Template::STATUSES)],
        ];
    }
}
