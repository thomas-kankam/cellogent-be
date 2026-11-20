<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Sms\Concerns\ValidatesSmsSchedule;

class ResendSmsRequest extends ApiFormRequest
{
    use ValidatesSmsSchedule;

    protected function prepareForValidation(): void
    {
        $this->prepareScheduleInput();

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return array_merge([
            'message' => ['required', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ], $this->scheduleRules());
    }

    public function messages(): array
    {
        return $this->scheduleMessages();
    }
}
