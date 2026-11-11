<?php

namespace App\Http\Requests\Campaign;

use App\Http\Requests\ApiFormRequest;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT). On update every field is optional and the type cannot change.
 * Type-specific rules (occasion vs promotion) are enforced by CampaignService.
 */
class StoreCampaignRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $update = $this->route('campaign') instanceof Campaign;
        $required = $update ? 'sometimes' : 'required';
        $maxGroups = (int) config('sms.bulk.max_groups', 20);

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$update ? 'sometimes' : 'required', Rule::in(Campaign::TYPES)],
            'occasion_type' => ['nullable', 'string', Rule::in(CampaignService::OCCASION_TYPES)],
            'custom_date' => ['nullable', 'date'],
            'trigger_offset' => ['nullable', 'string', Rule::in(CampaignService::TRIGGER_OFFSETS)],
            'custom_offset_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'repeat_yearly' => ['nullable', 'boolean'],
            'contact_group_ids' => [$required, 'array', 'min:1', "max:{$maxGroups}"],
            'contact_group_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'sender' => [$required, 'string', 'min:3', 'max:11'],
            'body' => [$required, 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
            'send_at' => ['nullable', 'date'],
            'cta' => ['nullable', 'array'],
            'cta.enabled' => ['nullable', 'boolean'],
            'cta.type' => ['nullable', 'string', 'in:link,ussd,phone'],
            'cta.value' => ['nullable', 'string', 'max:500'],
            'cta.url' => ['nullable', 'url', 'max:500'],
            'cta.label' => ['nullable', 'string', 'max:60'],
            'cta.triggers_reward' => ['nullable', 'boolean'],
            'reward_enabled' => ['nullable', 'boolean'],
            'reward_id' => ['nullable', 'integer', 'min:1'],
            'reward' => ['nullable', 'array'],
            'reward.enabled' => ['nullable', 'boolean'],
            'reward.type' => ['nullable', 'string', 'in:data,airtime,money'],
            'reward.amount' => ['nullable', 'numeric', 'min:0'],
            'reward.airtime_preset' => ['nullable', 'string', 'max:32'],
            'reward.bundle_id' => ['nullable', 'string', 'max:64'],
            'reward.bundle_label' => ['nullable', 'string', 'max:120'],
            'reward.currency' => ['nullable', 'string', 'size:3'],
            'reward.disbursement_method' => ['nullable', 'string', 'max:40'],
            'reward.network' => ['nullable', 'string', 'max:40'],
            'reward.reward_id' => ['nullable', 'integer', 'min:1'],
            'reward.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
