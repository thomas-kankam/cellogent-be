<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Sms\Concerns\ValidatesSmsSchedule;

class ReblastSmsRequest extends ApiFormRequest
{
    use ValidatesSmsSchedule;

    protected function prepareForValidation(): void
    {
        if (! $this->filled('target') && $this->filled('scope')) {
            $this->merge(['target' => $this->input('scope')]);
        }

        if ($this->filled('target')) {
            $this->merge(['target' => strtolower(trim((string) $this->input('target')))]);
        }

        $this->prepareScheduleInput();

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return array_merge([
            'from' => ['nullable', 'string', 'min:3', 'max:11'],
            'target' => ['nullable', 'string', 'in:failed,all'],
            'message' => ['nullable', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ], $this->scheduleRules());
    }

    public function messages(): array
    {
        return array_merge([
            'target.in' => 'target must be either "failed" (failed recipients only) or "all" (all recipients / contact groups).',
        ], $this->scheduleMessages());
    }
}
