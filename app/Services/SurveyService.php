<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\DispatchEngagementBlastJob;
use App\Models\Client;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Services\Engagement\EngagementSupport;
use App\Services\Payments\CheckoutService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Client surveys: SMS a link, collect answers on a public page, optionally pay a reward
 * to everyone who completes it (from a wallet hold).
 */
class SurveyService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public const FUND_METHODS = ['wallet', 'momo', 'card'];

    public const MAX_QUESTIONS = 50;

    public function __construct(
        protected EngagementSupport $support,
        protected WalletService $wallets,
        protected CheckoutService $checkout,
        protected RewardDisbursementService $rewards,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  status, search
     */
    public function index(Client $client, array $filters = []): Builder
    {
        $query = Survey::query()->withCount('questions')->where('client_id', $client->id)->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%');
        }

        return $query;
    }

    /**
     * Owner view.
     *
     * @return array<string, mixed>
     */
    public function present(Survey $survey): array
    {
        $survey->loadMissing('questions');
        $reward = is_array($survey->reward) ? $survey->reward : null;
        $summary = $this->summary($survey);

        return [
            'id' => $survey->id,
            'resource' => 'survey',
            'client_id' => $survey->client_id,
            'name' => $survey->name,
            'intro' => $survey->intro,
            'body' => $survey->body,
            'status' => $survey->status,
            'contact_group_ids' => array_map('intval', $survey->contact_group_ids ?? []),
            'sender' => $survey->sender,
            'send_at' => optional($survey->send_at)->toDateTimeString(),
            'public_token' => $survey->public_token,
            'survey_url' => $this->surveyUrl($survey),
            'questions' => $survey->questions->map(fn (SurveyQuestion $q) => $this->presentQuestion($q))->values()->all(),
            'reward' => [
                'enabled' => (bool) $survey->reward_enabled,
                'reward_id' => $survey->reward_id,
                'name' => $reward['name'] ?? null,
                'type' => $reward['type'] ?? null,
                'size' => $reward['size'] ?? null,
                'amount' => isset($reward['amount']) ? (float) $reward['amount'] : null,
                'network' => $reward['network'] ?? null,
                'currency' => $reward['currency'] ?? null,
                'requires_beneficiary' => $this->support->rewardNeedsBeneficiary($reward),
                'eligible_recipients' => (int) $survey->eligible_recipients,
                'not_eligible' => (int) $survey->not_eligible,
                'estimated_cost' => (float) $survey->estimated_cost,
                'beneficiary_message' => $this->support->rewardNeedsBeneficiary($reward)
                    ? "{$survey->eligible_recipients} verified beneficiaries can receive this data reward (others in the groups are skipped)."
                    : null,
            ],
            'funding' => [
                'method' => $survey->funding_method,
                'funded' => $this->support->isFunded($survey),
                'wallet_hold_id' => $survey->wallet_hold_id,
                'payment_id' => $survey->payment_id,
                'reserved_amount' => (float) $survey->reserved_amount,
                'reserved_remaining' => $summary['reserved_remaining'],
            ],
            'analytics' => $summary,
            'activated_at' => optional($survey->activated_at)->toDateTimeString(),
            'completed_at' => optional($survey->completed_at)->toDateTimeString(),
            'cancelled_at' => optional($survey->cancelled_at)->toDateTimeString(),
            'created_at' => optional($survey->created_at)->toDateTimeString(),
            'updated_at' => optional($survey->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * What a respondent may see: no funding, groups, counts or ownership details.
     *
     * @return array<string, mixed>
     */
    public function presentPublic(Survey $survey): array
    {
        $survey->loadMissing('questions');
        $reward = is_array($survey->reward) ? $survey->reward : null;

        return [
            'name' => $survey->name,
            'intro' => $survey->intro,
            'status' => $survey->status,
            'accepting_responses' => $survey->status === Survey::STATUS_ACTIVE,
            'questions' => $survey->questions->map(fn (SurveyQuestion $q) => $this->presentQuestion($q))->values()->all(),
            'reward' => $survey->reward_enabled && $reward ? [
                'name' => $reward['name'] ?? null,
                'type' => $reward['type'] ?? null,
                'size' => $reward['size'] ?? null,
                'amount' => isset($reward['amount']) ? (float) $reward['amount'] : null,
                'network' => $reward['network'] ?? null,
                'currency' => $reward['currency'] ?? null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentQuestion(SurveyQuestion $question): array
    {
        return [
            'id' => $question->id,
            'prompt' => $question->prompt,
            'type' => $question->type,
            'options' => $question->options,
            'required' => (bool) $question->required,
            'position' => (int) $question->position,
        ];
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function show(Survey $survey): array
    {
        return $this->result(false, 'Survey loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client, array $input): array
    {
        $attributes = $this->build($client, $input, null);

        if ($this->support->failed($attributes)) {
            return $attributes;
        }

        $questions = $this->questionRows($input['questions'] ?? []);

        if ($this->support->failed($questions)) {
            return $questions;
        }

        if ($questions === []) {
            return $this->invalid(['questions' => ['Add at least one question.']]);
        }

        $survey = DB::transaction(function () use ($client, $attributes, $questions) {
            $survey = Survey::create($attributes + [
                'client_id' => $client->id,
                'status' => Survey::STATUS_DRAFT,
                'public_token' => $this->newToken(),
                'reserved_amount' => 0,
                'analytics' => $this->emptyAnalytics(),
            ]);

            $this->syncQuestions($survey, $questions);

            return $survey;
        }, 3);

        $this->activity()->record('Survey created', 'survey', 'created', 'client', $client, $client, [
            'survey_id' => $survey->id,
        ]);

        return $this->result(false, 'Survey saved as draft.', ApiCode::CREATED, ApiReason::CREATED, $this->present($survey->fresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Client $client, Survey $survey, array $input): array
    {
        if (! in_array($survey->status, Survey::EDITABLE, true)) {
            return $this->result(true, 'Only draft, funded or paused surveys can be edited.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $attributes = $this->build($client, $input, $survey);

        if ($this->support->failed($attributes)) {
            return $attributes;
        }

        $questions = null;

        if (array_key_exists('questions', $input)) {
            $questions = $this->questionRows($input['questions'] ?? []);

            if ($this->support->failed($questions)) {
                return $questions;
            }

            if ($questions === []) {
                return $this->invalid(['questions' => ['Add at least one question.']]);
            }
        }

        $reserved = (float) $survey->reserved_amount;
        $reset = [];

        if ($survey->wallet_hold_id) {
            $needsMore = $attributes['reward_enabled'] && (float) $attributes['estimated_cost'] > $reserved + 0.00001;

            if (! $attributes['reward_enabled'] || $needsMore) {
                if ($survey->status === Survey::STATUS_PAUSED && $needsMore) {
                    return $this->result(true, 'The new audience or reward costs more than the reserved funds. Cancel this survey and create a new one.', ApiCode::BAD_REQUEST, ApiReason::INSUFFICIENT_BALANCE, [
                        'reserved' => $reserved,
                        'required' => (float) $attributes['estimated_cost'],
                    ]);
                }

                $this->support->releaseHold($survey);
                $reset = [
                    'wallet_hold_id' => null,
                    'funding_method' => null,
                    'payment_id' => null,
                    'reserved_amount' => 0,
                ];

                if ($survey->status === Survey::STATUS_FUNDED) {
                    $reset['status'] = Survey::STATUS_DRAFT;
                }
            }
        }

        DB::transaction(function () use ($survey, $attributes, $reset, $questions) {
            $survey->update($attributes + $reset);

            if ($questions !== null) {
                $this->syncQuestions($survey, $questions);
            }
        }, 3);

        $this->activity()->record('Survey updated', 'survey', 'updated', 'client', $client, $client, [
            'survey_id' => $survey->id,
        ]);

        return $this->result(false, 'Survey updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Client $client, Survey $survey): array
    {
        if (! in_array($survey->status, [Survey::STATUS_DRAFT, Survey::STATUS_FUNDED, Survey::STATUS_COMPLETED, Survey::STATUS_CANCELLED], true)) {
            return $this->result(true, 'Cancel or complete this survey before deleting it.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->support->releaseHold($survey);
        $survey->delete();

        $this->activity()->record('Survey deleted', 'survey', 'deleted', 'client', $client, $client, [
            'survey_id' => $survey->id,
        ]);

        return $this->result(false, 'Survey deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $input  method, phone_number, network, estimated_cost
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function fund(Client $client, Survey $survey, array $input): array
    {
        $method = (string) ($input['method'] ?? 'wallet');

        if (! in_array($method, self::FUND_METHODS, true)) {
            return $this->result(true, 'Choose a valid payment method.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'method' => ['Allowed: '.implode(', ', self::FUND_METHODS)],
            ]);
        }

        if (! $survey->reward_enabled) {
            return $this->result(false, 'This survey has no reward, so no funding is needed. You can activate it.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey));
        }

        if ($this->support->isFunded($survey) || $survey->status !== Survey::STATUS_DRAFT) {
            return $this->result(true, 'Only unfunded draft surveys can be funded.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $refreshed = $this->refreshEstimate($client, $survey, $input['estimated_cost'] ?? null);

        if ($this->support->failed($refreshed)) {
            return $refreshed;
        }

        $survey = $refreshed;
        $required = (float) $survey->estimated_cost;

        if ($survey->eligible_recipients < 1 || $required <= 0) {
            return $this->result(true, 'There are no eligible recipients to reward in the selected groups.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($method !== 'wallet') {
            $started = $this->checkout->start($client, 'survey_fund', [
                'method' => $method,
                'phone_number' => $input['phone_number'] ?? null,
                'network' => $input['network'] ?? null,
                'amount' => $required,
                'name' => 'Survey funding: '.$survey->name,
                'survey_id' => $survey->id,
            ]);

            if (! $started['in_error']) {
                $survey->update(['funding_method' => $method, 'payment_id' => $started['data']['id'] ?? null]);
                $started['data']['survey_id'] = $survey->id;
            }

            return $started;
        }

        $outcome = DB::transaction(function () use ($client, $survey, $required) {
            $locked = Survey::query()->lockForUpdate()->find($survey->id);

            if (! $locked || $locked->status !== Survey::STATUS_DRAFT || $locked->wallet_hold_id) {
                return $this->result(true, 'Only unfunded draft surveys can be funded.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
            }

            $hold = $this->support->holdFromWallet($client, $locked, $required, 'survey_fund', ['survey_id' => $locked->id]);

            if ($this->support->failed($hold)) {
                return $hold;
            }

            $locked->update([
                'wallet_hold_id' => $hold->id,
                'funding_method' => 'wallet',
                'reserved_amount' => $hold->amount,
                'status' => Survey::STATUS_FUNDED,
            ]);

            return null;
        }, 3);

        if ($outcome) {
            return $outcome;
        }

        $this->activity()->record('Survey funded', 'survey', 'funded', 'client', $client, $client, [
            'survey_id' => $survey->id,
            'method' => 'wallet',
            'amount' => $required,
        ]);

        return $this->result(false, 'Survey funded from your wallet.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function activate(Client $client, Survey $survey): array
    {
        $ready = $survey->status === Survey::STATUS_FUNDED
            || ($survey->status === Survey::STATUS_DRAFT && ! $survey->reward_enabled);

        if (! $ready) {
            $message = $survey->status === Survey::STATUS_DRAFT && $survey->reward_enabled
                ? 'Fund this survey before activating it.'
                : 'Only funded surveys, or drafts without a reward, can be activated.';

            return $this->result(true, $message, ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($survey->reward_enabled && ! $this->support->isFunded($survey)) {
            return $this->result(true, 'The reserved funds for this survey are no longer available. Fund it again.', ApiCode::BAD_REQUEST, ApiReason::FUNDS_RESERVED);
        }

        if (! $survey->questions()->exists()) {
            return $this->result(true, 'Add at least one question before activating.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($survey->eligible_recipients < 1) {
            return $this->result(true, 'There are no eligible recipients in the selected groups.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $sender = $this->support->sender($client, (string) $survey->sender);

        if ($this->support->failed($sender)) {
            return $sender;
        }

        $survey->update([
            'status' => $this->liveStatus($survey),
            'activated_at' => $survey->activated_at ?? now(),
        ]);

        $this->activity()->record('Survey activated', 'survey', 'activated', 'client', $client, $client, [
            'survey_id' => $survey->id,
            'status' => $survey->status,
        ]);

        // Send the SMS now unless the survey is scheduled for later (engagement:dispatch-due sends those).
        if ($survey->status === Survey::STATUS_ACTIVE) {
            DispatchEngagementBlastJob::dispatch('survey', $survey->id)->afterCommit();
        }

        return $this->result(false, 'Survey activated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function pause(Client $client, Survey $survey): array
    {
        return $this->transition($client, $survey, [Survey::STATUS_ACTIVE, Survey::STATUS_SCHEDULED], Survey::STATUS_PAUSED, 'paused', 'Survey paused.');
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function resume(Client $client, Survey $survey): array
    {
        if ($survey->reward_enabled && ! $this->support->isFunded($survey)) {
            return $this->result(true, 'The reserved funds for this survey are no longer available.', ApiCode::BAD_REQUEST, ApiReason::FUNDS_RESERVED);
        }

        return $this->transition($client, $survey, [Survey::STATUS_PAUSED], $this->liveStatus($survey), 'resumed', 'Survey resumed.');
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function complete(Client $client, Survey $survey): array
    {
        return $this->transition(
            $client,
            $survey,
            [Survey::STATUS_ACTIVE, Survey::STATUS_SCHEDULED, Survey::STATUS_PAUSED],
            Survey::STATUS_COMPLETED,
            'completed',
            'Survey completed. Unused reserved funds were released.',
            ['completed_at' => now()],
            true,
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function cancel(Client $client, Survey $survey): array
    {
        return $this->transition(
            $client,
            $survey,
            [Survey::STATUS_ACTIVE, Survey::STATUS_SCHEDULED, Survey::STATUS_PAUSED, Survey::STATUS_FUNDED],
            Survey::STATUS_CANCELLED,
            'cancelled',
            'Survey cancelled. Unused reserved funds were released.',
            ['cancelled_at' => now()],
            true,
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function analytics(Survey $survey): array
    {
        return $this->result(false, 'Survey analytics loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->summary($survey));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Survey $survey): array
    {
        $stored = is_array($survey->analytics) ? $survey->analytics : [];

        $rows = SurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->selectRaw('COUNT(clicked_at) as clicked, COUNT(responded_at) as responded')
            ->first();

        $rewards = SurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->whereNotNull('reward_status')
            ->selectRaw('reward_status, COUNT(*) as total')
            ->groupBy('reward_status')
            ->pluck('total', 'reward_status');

        $eligible = (int) $survey->eligible_recipients;
        $blasted = max((int) ($stored['blasted'] ?? 0), (int) ($stored['sent'] ?? 0));
        $clicks = max((int) ($stored['clicks'] ?? 0), (int) ($rows->clicked ?? 0));
        $responded = (int) ($rows->responded ?? 0);
        $disbursed = (int) ($rewards['disbursed'] ?? 0);
        $unit = EngagementSupport::unitCost(is_array($survey->reward) ? $survey->reward : null);

        return [
            'blasted' => $blasted,
            'clicks' => $clicks,
            'responded' => $responded,
            'not_responded' => max(0, $eligible - $responded),
            'completion_rate' => $clicks > 0 ? min(100, round($responded / $clicks * 100, 2)) : 0.0,
            'response_rate' => $eligible > 0 ? min(100, round($responded / $eligible * 100, 2)) : 0.0,
            'rewards_pending' => (int) ($rewards['pending'] ?? 0),
            'rewards_disbursed' => $disbursed,
            'rewards_failed' => (int) ($rewards['failed'] ?? 0),
            'reserved_remaining' => $this->support->holdRemaining($survey),
            'estimated_cost' => (float) $survey->estimated_cost,
            'actual_cost' => isset($stored['actual_cost']) ? round((float) $stored['actual_cost'], 2) : round($disbursed * $unit, 2),
            'eligible_recipients' => $eligible,
        ];
    }

    public function findByToken(string $token): ?Survey
    {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        return Survey::query()->with('questions')->where('public_token', $token)->first();
    }

    /**
     * Public survey page. Drafts and cancelled surveys are not visible.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function publicShow(string $token): array
    {
        $survey = $this->findByToken($token);

        if (! $survey || ! $this->isVisible($survey)) {
            return $this->result(true, 'Survey not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->result(false, 'Survey loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->presentPublic($survey));
    }

    /**
     * Someone opened the link. With a phone we remember who; without, only the counter moves.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function click(string $token, ?string $phone = null): array
    {
        $survey = $this->findByToken($token);

        if (! $survey || ! $this->isVisible($survey)) {
            return $this->result(true, 'Survey not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $mobile = GhanaPhone::toMobile($phone);

        DB::transaction(function () use ($survey, $mobile) {
            $locked = Survey::query()->lockForUpdate()->find($survey->id);
            $firstClick = true;

            if ($mobile) {
                $response = SurveyResponse::query()
                    ->where('survey_id', $survey->id)
                    ->where('phone', $mobile)
                    ->lockForUpdate()
                    ->first();

                if (! $response) {
                    $contact = $this->support->contactForPhone($survey->client, array_map('intval', $survey->contact_group_ids ?? []), $mobile);

                    try {
                        SurveyResponse::create([
                            'survey_id' => $survey->id,
                            'contact_id' => $contact?->id,
                            'phone' => $mobile,
                            'clicked_at' => now(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        $firstClick = false;
                    }
                } elseif (! $response->clicked_at) {
                    $response->update(['clicked_at' => now()]);
                } else {
                    $firstClick = false;
                }
            }

            if ($firstClick) {
                $analytics = is_array($locked->analytics) ? $locked->analytics : [];
                $analytics['clicks'] = (int) ($analytics['clicks'] ?? 0) + 1;
                $locked->update(['analytics' => $analytics]);
            }
        }, 3);

        return $this->result(false, 'Click recorded.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * Save a respondent's answers. One submission per phone; a queued reward is marked pending.
     *
     * @param  array<int|string, mixed>  $answers  [{question_id, answer}] or {question_id: answer}
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function submit(string $token, ?string $phone, array $answers): array
    {
        $survey = $this->findByToken($token);

        if (! $survey || ! $this->isVisible($survey)) {
            return $this->result(true, 'Survey not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($survey->status !== Survey::STATUS_ACTIVE) {
            return $this->result(true, 'This survey is not accepting responses.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $mobile = GhanaPhone::toMobile($phone);

        if (! $mobile) {
            return $this->result(true, 'Enter a valid phone number.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'phone' => ['Enter a valid phone number.'],
            ]);
        }

        $groupIds = array_map('intval', $survey->contact_group_ids ?? []);
        $contact = $this->support->contactForPhone($survey->client, $groupIds, $mobile);

        // Rewards go only to the people the survey was sent to.
        if ($survey->reward_enabled && ! $contact) {
            return $this->result(true, 'This number is not on the list for this survey.', ApiCode::FORBIDDEN, ApiReason::FORBIDDEN);
        }

        $clean = $this->cleanAnswers($survey, $answers);

        if ($this->support->failed($clean)) {
            return $clean;
        }

        try {
            $outcome = DB::transaction(function () use ($survey, $mobile, $contact, $clean) {
                $locked = Survey::query()->lockForUpdate()->find($survey->id);

                if (! $locked || $locked->status !== Survey::STATUS_ACTIVE) {
                    return $this->result(true, 'This survey is not accepting responses.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
                }

                $response = SurveyResponse::query()
                    ->where('survey_id', $survey->id)
                    ->where('phone', $mobile)
                    ->lockForUpdate()
                    ->first();

                if ($response?->responded_at) {
                    return $this->result(true, 'You have already completed this survey.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
                }

                $analytics = is_array($locked->analytics) ? $locked->analytics : [];

                if (! $response || ! $response->clicked_at) {
                    $analytics['clicks'] = (int) ($analytics['clicks'] ?? 0) + 1;
                    $locked->update(['analytics' => $analytics]);
                }

                $response ??= new SurveyResponse(['survey_id' => $survey->id, 'phone' => $mobile]);

                $rewardStatus = null;
                $rewardMeta = null;

                if ($locked->reward_enabled && is_array($locked->reward)) {
                    $needsBeneficiary = $this->support->rewardNeedsBeneficiary($locked->reward);
                    $canReward = ! $needsBeneficiary
                        || ($contact && $contact->beneficiary_status === \App\Models\Contact::BENEFICIARY_VERIFIED);

                    $unit = EngagementSupport::unitCost($locked->reward);
                    $committed = (float) SurveyResponse::query()
                        ->where('survey_id', $survey->id)
                        ->where('reward_status', 'pending')
                        ->count() * $unit;
                    $room = $this->support->holdRemaining($locked) - $committed;

                    $rewardStatus = ! $canReward
                        ? 'ineligible'
                        : ($unit > 0 && $room + 0.00001 >= $unit ? 'pending' : 'exhausted');
                    $rewardMeta = [
                        'reward_id' => $locked->reward['reward_id'] ?? null,
                        'type' => $locked->reward['type'] ?? null,
                        'amount' => $unit,
                        'queued_at' => now()->toDateTimeString(),
                        'beneficiary_required' => $needsBeneficiary,
                    ];
                }

                $response->fill([
                    'contact_id' => $contact?->id,
                    'clicked_at' => $response->clicked_at ?? now(),
                    'responded_at' => now(),
                    'answers' => $clean,
                    'reward_status' => $rewardStatus,
                    'reward_meta' => $rewardMeta,
                ])->save();

                return $rewardStatus;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->result(true, 'You have already completed this survey.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        if (is_array($outcome)) {
            return $outcome;
        }

        if ($outcome === 'pending') {
            $response = SurveyResponse::query()->where('survey_id', $survey->id)->where('phone', $mobile)->first();

            if ($response) {
                $this->rewards->queueFromSurveyResponse($survey, $response);
            }
        }

        return $this->result(false, 'Thank you! Your response was saved.', ApiCode::CREATED, ApiReason::CREATED, [
            'reward_status' => $outcome,
        ]);
    }

    /**
     * @return Survey|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function refreshEstimate(Client $client, Survey $survey, mixed $override): Survey|array
    {
        $groups = $this->support->groupIds($client, $survey->contact_group_ids ?? []);

        if ($this->support->failed($groups)) {
            return $groups;
        }

        $snapshot = is_array($survey->reward) ? $survey->reward : null;
        $audience = $this->support->audience(
            $client,
            $groups,
            true,
            $survey->reward_enabled && $this->support->rewardNeedsBeneficiary($snapshot),
        );

        if ($snapshot && is_numeric($override) && (float) $override > 0 && ($snapshot['type'] ?? null) === 'data') {
            $snapshot['estimate_override'] = round((float) $override, 2);
        }

        $survey->update([
            'reward' => $snapshot,
            'eligible_recipients' => $audience['eligible'],
            'not_eligible' => $audience['not_eligible'],
            'estimated_cost' => $this->support->estimate($snapshot, $audience['eligible'], $snapshot['estimate_override'] ?? null),
        ]);

        return $survey->fresh();
    }

    protected function liveStatus(Survey $survey): string
    {
        return $survey->send_at && $survey->send_at->isFuture() ? Survey::STATUS_SCHEDULED : Survey::STATUS_ACTIVE;
    }

    protected function isVisible(Survey $survey): bool
    {
        return in_array($survey->status, [Survey::STATUS_ACTIVE, Survey::STATUS_PAUSED, Survey::STATUS_COMPLETED], true);
    }

    protected function surveyUrl(Survey $survey): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return $base.'/surveys/'.$survey->public_token;
    }

    protected function newToken(): string
    {
        do {
            $token = Str::random(48);
        } while (Survey::withTrashed()->where('public_token', $token)->exists());

        return $token;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function transition(Client $client, Survey $survey, array $from, string $to, string $action, string $message, array $extra = [], bool $release = false): array
    {
        $moved = DB::transaction(function () use ($survey, $from, $to, $extra, $release) {
            $locked = Survey::query()->lockForUpdate()->find($survey->id);

            if (! $locked || ! in_array($locked->status, $from, true)) {
                return false;
            }

            $locked->update(['status' => $to] + $extra);

            if ($release) {
                $this->support->releaseHold($locked);
            }

            return true;
        }, 3);

        if (! $moved) {
            return $this->result(true, 'This action is not available for a '.$survey->fresh()->status.' survey.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->activity()->record('Survey '.$action, 'survey', $action, 'client', $client, $client, [
            'survey_id' => $survey->id,
        ]);

        return $this->result(false, $message, ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($survey->fresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function build(Client $client, array $input, ?Survey $existing): array
    {
        $get = function (string $key, mixed $default = null) use ($input, $existing) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }

            return $existing?->{$key} ?? $default;
        };

        $errors = [];
        $name = trim((string) $get('name', ''));

        if ($name === '') {
            $errors['name'] = ['A survey name is required.'];
        }

        $sendAt = $existing?->send_at;

        if (array_key_exists('send_at', $input)) {
            $sendAt = $input['send_at'] ? Carbon::parse($input['send_at']) : null;

            if ($sendAt && $sendAt->isPast()) {
                $errors['send_at'] = ['The send time must be in the future.'];
            }
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $sender = $this->support->sender($client, (string) $get('sender', ''));

        if ($this->support->failed($sender)) {
            return $sender;
        }

        $groups = $this->support->groupIds($client, $get('contact_group_ids', []));

        if ($this->support->failed($groups)) {
            return $groups;
        }

        $reward = $this->rewardFields($input, $existing);

        if ($this->support->failed($reward)) {
            return $reward;
        }

        $needsBeneficiary = $reward['enabled'] && $this->support->rewardNeedsBeneficiary($reward['snapshot'] ?? null);
        $audience = $this->support->audience($client, $groups, true, $needsBeneficiary);

        return [
            'name' => $name,
            'intro' => $get('intro'),
            'body' => $get('body'),
            'contact_group_ids' => $groups,
            'sender' => $sender,
            'send_at' => $sendAt,
            'reward_enabled' => $reward['enabled'],
            'reward' => $reward['snapshot'],
            'reward_id' => $reward['reward_id'],
            'eligible_recipients' => $audience['eligible'],
            'not_eligible' => $audience['not_eligible'],
            'estimated_cost' => $reward['enabled']
                ? $this->support->estimate($reward['snapshot'], $audience['eligible'], $reward['snapshot']['estimate_override'] ?? null)
                : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, snapshot: array<string, mixed>|null, reward_id: int|null}|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function rewardFields(array $input, ?Survey $existing): array
    {
        $given = $this->support->rewardInput($input);
        $override = $input['estimated_cost'] ?? ($given['inline']['estimate_override'] ?? null);

        if (! $given['present']) {
            $snapshot = $existing && is_array($existing->reward) ? $existing->reward : null;
            $enabled = (bool) ($existing?->reward_enabled ?? false);
            $id = $existing?->reward_id;
        } elseif (! $given['enabled']) {
            return ['enabled' => false, 'snapshot' => null, 'reward_id' => null];
        } elseif ($given['reward_id']) {
            $reward = $this->support->reward($given['reward_id']);

            if ($this->support->failed($reward)) {
                return $reward;
            }

            $snapshot = $this->support->snapshot($reward);
            $enabled = true;
            $id = $reward->id;
        } elseif (is_array($given['inline'] ?? null)) {
            $snapshot = $this->support->inlineSnapshot($given['inline']);

            if ($this->support->failed($snapshot)) {
                return $snapshot;
            }

            $enabled = true;
            $id = null;
        } elseif ($existing && is_array($existing->reward)) {
            $snapshot = $existing->reward;
            $enabled = true;
            $id = $existing->reward_id;
        } else {
            return $this->result(true, 'Provide a reward type and amount, or a catalog reward_id.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'reward' => ['Provide a reward type and amount, or a catalog reward_id.'],
            ]);
        }

        if ($snapshot && is_numeric($override) && (float) $override > 0 && ($snapshot['type'] ?? null) === 'data') {
            $snapshot['estimate_override'] = round((float) $override, 2);
        }

        return ['enabled' => $enabled && $snapshot !== null, 'snapshot' => $snapshot, 'reward_id' => $id];
    }

    /**
     * Validate and normalize the questions array.
     *
     * @return list<array<string, mixed>>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function questionRows(mixed $questions): array
    {
        if (! is_array($questions)) {
            return $this->invalid(['questions' => ['Questions must be a list.']]);
        }

        if (count($questions) > self::MAX_QUESTIONS) {
            return $this->invalid(['questions' => ['A survey can have at most '.self::MAX_QUESTIONS.' questions.']]);
        }

        $rows = [];
        $errors = [];

        foreach (array_values($questions) as $index => $question) {
            $question = is_array($question) ? $question : [];
            $prompt = trim((string) ($question['prompt'] ?? ''));
            $type = (string) ($question['type'] ?? '');
            $options = null;

            if ($prompt === '') {
                $errors["questions.{$index}.prompt"] = ['The question is required.'];
            }

            if (! in_array($type, Survey::QUESTION_TYPES, true)) {
                $errors["questions.{$index}.type"] = ['Allowed: '.implode(', ', Survey::QUESTION_TYPES)];
            } elseif (in_array($type, ['single_choice', 'multiple_choice'], true)) {
                $options = collect($question['options'] ?? [])
                    ->filter(fn ($option) => is_scalar($option) && trim((string) $option) !== '')
                    ->map(fn ($option) => trim((string) $option))
                    ->unique()
                    ->values()
                    ->all();

                if (count($options) < 2) {
                    $errors["questions.{$index}.options"] = ['Add at least two different options.'];
                }
            }

            $rows[] = [
                'id' => isset($question['id']) ? (int) $question['id'] : null,
                'prompt' => $prompt,
                'type' => $type,
                'options' => $options,
                'required' => array_key_exists('required', $question) ? filter_var($question['required'], FILTER_VALIDATE_BOOLEAN) : true,
                'position' => isset($question['position']) && is_numeric($question['position']) ? (int) $question['position'] : $index,
            ];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        return $rows;
    }

    /**
     * Update questions that carry one of this survey's ids, create the rest, drop what was left out.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function syncQuestions(Survey $survey, array $rows): void
    {
        $existing = $survey->questions()->get()->keyBy('id');
        $keep = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            unset($row['id']);

            if ($id && $existing->has($id)) {
                $existing[$id]->update($row);
                $keep[] = $id;

                continue;
            }

            $keep[] = $survey->questions()->create($row)->id;
        }

        $survey->questions()->whereNotIn('id', $keep)->delete();
        $survey->unsetRelation('questions');
    }

    /**
     * Check answers against the questions and return a clean, self-describing list.
     *
     * @param  array<int|string, mixed>  $answers
     * @return list<array<string, mixed>>|array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function cleanAnswers(Survey $survey, array $answers): array
    {
        $given = [];

        foreach ($answers as $key => $value) {
            if (is_array($value) && array_key_exists('question_id', $value)) {
                $given[(int) $value['question_id']] = $value['answer'] ?? null;
            } elseif (is_numeric($key)) {
                $given[(int) $key] = $value;
            }
        }

        $clean = [];
        $errors = [];

        foreach ($survey->questions as $question) {
            $value = $given[$question->id] ?? null;
            $blank = $value === null || $value === '' || $value === [];

            if ($blank) {
                if ($question->required) {
                    $errors["answers.{$question->id}"] = ['This question is required.'];
                }

                continue;
            }

            $normalized = $this->normalizeAnswer($question, $value);

            if ($normalized === null) {
                $errors["answers.{$question->id}"] = ['This answer is not valid.'];

                continue;
            }

            $clean[] = [
                'question_id' => $question->id,
                'prompt' => $question->prompt,
                'type' => $question->type,
                'answer' => $normalized['value'],
            ];
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        return $clean;
    }

    /**
     * @return array{value: mixed}|null null when the answer is not valid for the question type
     */
    protected function normalizeAnswer(SurveyQuestion $question, mixed $value): ?array
    {
        $options = $question->options ?? [];

        switch ($question->type) {
            case 'text':
                return is_scalar($value) && mb_strlen((string) $value) <= 500 ? ['value' => trim((string) $value)] : null;
            case 'long_text':
                return is_scalar($value) && mb_strlen((string) $value) <= 5000 ? ['value' => trim((string) $value)] : null;
            case 'single_choice':
                return is_scalar($value) && in_array((string) $value, $options, true) ? ['value' => (string) $value] : null;
            case 'multiple_choice':
                if (! is_array($value)) {
                    return null;
                }

                $picked = array_values(array_unique(array_map('strval', $value)));

                return array_diff($picked, $options) === [] ? ['value' => $picked] : null;
            case 'rating':
                return is_numeric($value) && (int) $value == $value && $value >= 1 && $value <= 5 ? ['value' => (int) $value] : null;
            case 'yes_no':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool === null ? null : ['value' => $bool ? 'yes' : 'no'];
            case 'number':
                return is_numeric($value) ? ['value' => $value + 0] : null;
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function invalid(array $errors): array
    {
        return $this->result(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $errors);
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptyAnalytics(): array
    {
        return [
            'blasted' => 0,
            'clicks' => 0,
            'actual_cost' => 0,
        ];
    }
}
