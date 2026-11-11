<?php

namespace App\Http\Requests\Campaign;

use App\Http\Requests\ApiFormRequest;
use App\Models\CampaignEvent;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

class RecordCampaignEventRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::in(CampaignEvent::EVENTS)],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'meta' => ['nullable', 'array'],
        ];
    }
}
