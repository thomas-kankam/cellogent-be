<?php

namespace App\Http\Requests\Sms\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * Shared validation for scheduling Quick SMS and Bulk SMS ("send now" vs "send later").
 *
 * Supported payload shapes:
 * - Immediate (default): omit schedule fields, or send `"send_mode": "now"`.
 * - Scheduled (single field): `"send_mode": "later"` (optional) + `"scheduled_at": "2026-11-25 14:30:00"` (or `"send_at"`).
 * - Scheduled (separate date + time): `"send_mode": "later"` (optional) + `"scheduled_date": "2026-11-25"` + `"scheduled_time": "14:30"` (or `"send_date"` / `"send_time"`).
 */
trait ValidatesSmsSchedule
{
    protected bool $scheduleDateOnlyWithoutTime = false;

    protected function prepareScheduleInput(): void
    {
        $this->scheduleDateOnlyWithoutTime = false;

        $mode = $this->filled('send_mode') ? strtolower(trim((string) $this->input('send_mode'))) : null;

        if ($mode === 'now') {
            $this->merge([
                'send_mode' => 'now',
                'scheduled_at' => null,
            ]);

            return;
        }

        if ($mode !== null) {
            $this->merge(['send_mode' => $mode]);
        }

        $rawAt = $this->filled('scheduled_at')
            ? trim((string) $this->input('scheduled_at'))
            : ($this->filled('send_at') ? trim((string) $this->input('send_at')) : null);

        $rawDate = $this->filled('scheduled_date')
            ? trim((string) $this->input('scheduled_date'))
            : ($this->filled('send_date') ? trim((string) $this->input('send_date')) : null);

        $rawTime = $this->filled('scheduled_time')
            ? trim((string) $this->input('scheduled_time'))
            : ($this->filled('send_time') ? trim((string) $this->input('send_time')) : null);

        // If `scheduled_at` / `send_at` was given as a date only (YYYY-MM-DD), combine with time if provided.
        if ($rawAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawAt) === 1) {
            if ($rawTime !== null) {
                $this->merge(['scheduled_at' => $rawAt.' '.$rawTime]);
            } else {
                $this->scheduleDateOnlyWithoutTime = true;
                $this->merge(['scheduled_at' => $rawAt]);
            }

            return;
        }

        if ($rawAt !== null) {
            $this->merge(['scheduled_at' => $rawAt]);

            return;
        }

        if ($rawDate !== null) {
            if ($rawTime !== null) {
                $this->merge(['scheduled_at' => $rawDate.' '.$rawTime]);
            } else {
                $this->scheduleDateOnlyWithoutTime = true;
                $this->merge(['scheduled_at' => $rawDate]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function scheduleRules(): array
    {
        return [
            'send_mode' => ['nullable', 'string', 'in:now,later,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'send_at' => ['nullable', 'date'],
            'send_date' => ['nullable', 'date_format:Y-m-d'],
            'send_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function scheduleMessages(): array
    {
        return [
            'send_mode.in' => 'send_mode must be "now" or "later".',
            'scheduled_at.date' => 'Provide a valid scheduled date and time (e.g. 2026-11-25 14:30).',
            'scheduled_date.date_format' => 'scheduled_date must be in YYYY-MM-DD format.',
            'scheduled_time.regex' => 'scheduled_time must be in 24-hour HH:MM format (e.g. 14:30).',
            'send_date.date_format' => 'send_date must be in YYYY-MM-DD format.',
            'send_time.regex' => 'send_time must be in 24-hour HH:MM format (e.g. 14:30).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('send_mode');
            $scheduledAt = $this->input('scheduled_at');
            $hasTimeOnly = ($this->filled('scheduled_time') || $this->filled('send_time'))
                && ! $this->filled('scheduled_at')
                && ! $this->filled('send_at')
                && ! $this->filled('scheduled_date')
                && ! $this->filled('send_date');

            if ($mode === 'now') {
                return;
            }

            if ($hasTimeOnly) {
                $validator->errors()->add('scheduled_date', 'Provide the scheduled date along with the time.');

                return;
            }

            if (in_array($mode, ['later', 'scheduled'], true) && empty($scheduledAt)) {
                $validator->errors()->add('scheduled_at', 'Provide a scheduled date and time when sending later.');

                return;
            }

            if ($this->scheduleDateOnlyWithoutTime) {
                $validator->errors()->add('scheduled_time', 'Provide a time (HH:MM) along with the scheduled date.');

                return;
            }

            if (! empty($scheduledAt) && ! $validator->errors()->has('scheduled_at')) {
                try {
                    $parsed = Carbon::parse((string) $scheduledAt);

                    if (! $parsed->isFuture()) {
                        $validator->errors()->add('scheduled_at', 'Scheduled date and time must be in the future.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('scheduled_at', 'Provide a valid scheduled date and time.');
                }
            }
        });
    }
}
