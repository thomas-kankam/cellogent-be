<?php

namespace App\Http\Requests;

use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use App\Support\GhanaPhone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Every API form request returns the same envelope on validation failure.
 * Phone fields are normalized to 233XXXXXXXXX before rules run:
 * - phone_number (profiles, contacts, payments)
 * - to (SMS recipients — string, CSV, or array; never phone_number for SMS)
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Resource-specific ID aliases accepted interchangeably with `id` (and singular/plural contact groups).
     *
     * @var list<string>
     */
    protected const RESOURCE_ID_ALIASES = [
        'inbox_id',
        'sms_id',
        'sender_id',
        'contact_group_id',
        'contact_id',
        'campaign_id',
        'survey_id',
        'template_id',
        'plan_id',
        'payment_id',
        'order_id',
        'utility_order_id',
        'subscription_id',
        'wallet_id',
        'api_key_id',
        'client_id',
        'admin_id',
        'role_id',
    ];

    protected function prepareForValidation(): void
    {
        $payload = [];

        // Non-scalar values are left as-is so the 'string' rule rejects them with a 422 (not a TypeError 500).
        if ($this->exists('phone_number') && is_scalar($this->input('phone_number'))) {
            $payload['phone_number'] = GhanaPhone::normalize((string) $this->input('phone_number'));
        }

        if ($this->exists('to')) {
            $payload['to'] = $this->normalizeToField($this->input('to'));
        }

        if (is_array($this->input('meta_data')) && array_key_exists('phone', $this->input('meta_data'))) {
            $meta = $this->input('meta_data');
            $meta['phone'] = is_scalar($meta['phone']) ? GhanaPhone::normalize((string) $meta['phone']) : $meta['phone'];
            $payload['meta_data'] = $meta;
        }

        $payload += $this->normalizeResourceIdAliases();

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * Allow callers to pass either `id` or `<resource>_id` (e.g. `inbox_id`, `plan_id`, `contact_group_id`)
     * interchangeably based on whichever key this request's validation rules expect.
     *
     * @return array<string, mixed>
     */
    protected function normalizeResourceIdAliases(): array
    {
        if (! method_exists($this, 'rules')) {
            return [];
        }

        $rules = $this->rules();
        $mapped = [];

        // If the request expects `<resource>_id` (e.g. `plan_id`) and caller passed `id`, map it across.
        if ($this->filled('id')) {
            $expectedAliases = array_values(array_intersect(self::RESOURCE_ID_ALIASES, array_keys($rules)));

            if (count($expectedAliases) === 1 && ! $this->filled($expectedAliases[0])) {
                $mapped[$expectedAliases[0]] = $this->input('id');
            }
        }

        // If the request expects `id` and caller passed a `<resource>_id` alias (e.g. `inbox_id`), map it to `id`.
        if (array_key_exists('id', $rules) && ! $this->filled('id')) {
            foreach (self::RESOURCE_ID_ALIASES as $alias) {
                if ($this->filled($alias)) {
                    $mapped['id'] = $this->input($alias);
                    break;
                }
            }
        }

        // Convenience: if a request expects `contact_group_ids` and caller passed singular `contact_group_id`.
        if (array_key_exists('contact_group_ids', $rules) && ! $this->filled('contact_group_ids') && $this->filled('contact_group_id')) {
            $raw = $this->input('contact_group_id');
            $mapped['contact_group_ids'] = is_array($raw) ? $raw : [$raw];
        }

        return $mapped;
    }

    /**
     * SMS recipient field: one number, CSV/space list, or array → list of normalized strings.
     *
     * @return list<string>|mixed
     */
    protected function normalizeToField(mixed $to): mixed
    {
        if (is_string($to) || is_numeric($to)) {
            $parts = preg_split('/[\s,;]+/', trim((string) $to), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_map(
                fn (string $n) => GhanaPhone::normalize($n) ?? $n,
                $parts,
            ));
        }

        if (! is_array($to)) {
            return $to;
        }

        return array_values(array_map(function ($n) {
            if (! is_scalar($n)) {
                return $n;
            }

            $normalized = GhanaPhone::normalize((string) $n);

            return $normalized ?? (string) $n;
        }, $to));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::make(
            true,
            'Validation failed.',
            ApiCode::VALIDATION,
            ApiReason::VALIDATION_ERROR,
            $validator->errors()->toArray(),
        ));
    }
}
