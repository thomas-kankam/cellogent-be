<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Survey;
use App\Models\Template;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ContactPlaceholders;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable templates for Quick SMS, Bulk SMS, Promotion/Occasion Campaigns, and Surveys.
 *
 * - Admin templates (`scope = global`, `client_id = null`) are visible to all clients; only admins can edit/delete them.
 * - Client templates (`scope = client`, `client_id = client.id`) are private to that client; only that client can view/edit/delete them.
 */
class TemplateService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @param  array<string, mixed>  $filters  type, occasion_type, category, status, search
     */
    public function indexForAdmin(array $filters = []): Builder
    {
        $query = Template::query()
            ->where('scope', Template::SCOPE_GLOBAL)
            ->latest('id');

        return $this->applyFilters($query, $filters, allowStatus: true);
    }

    /**
     * Returns active admin/global templates plus this client's own private templates.
     *
     * By default (combined view), deactivated client templates and deactivated admin templates are hidden.
     * Clients can view their deactivated templates via `owner=mine`, `status=inactive`, or `include_inactive=1`.
     *
     * @param  array<string, mixed>  $filters  type, occasion_type, category, owner/scope, status, include_inactive, search
     */
    public function indexForClient(Client $client, array $filters = []): Builder
    {
        $owner = strtolower(trim((string) ($filters['owner'] ?? $filters['scope'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = Template::query()->where(function (Builder $q) use ($client, $owner, $status, $includeInactive) {
            if ($owner === 'mine' || $owner === 'client') {
                $q->where('scope', Template::SCOPE_CLIENT)
                    ->where('client_id', $client->id);

                if (in_array($status, Template::STATUSES, true)) {
                    $q->where('status', $status);
                }

                return;
            }

            if ($owner === 'global' || $owner === 'admin' || $owner === 'system') {
                $q->where('scope', Template::SCOPE_GLOBAL)
                    ->whereNull('client_id')
                    ->where('status', Template::STATUS_ACTIVE);

                return;
            }

            // Combined view (client's own + system/admin templates)
            if ($status === Template::STATUS_INACTIVE) {
                // Only client's own deactivated templates; deactivated admin templates stay hidden.
                $q->where('scope', Template::SCOPE_CLIENT)
                    ->where('client_id', $client->id)
                    ->where('status', Template::STATUS_INACTIVE);

                return;
            }

            $q->where(function (Builder $global) {
                $global->where('scope', Template::SCOPE_GLOBAL)
                    ->whereNull('client_id')
                    ->where('status', Template::STATUS_ACTIVE);
            })->orWhere(function (Builder $mine) use ($client, $status, $includeInactive) {
                $mine->where('scope', Template::SCOPE_CLIENT)
                    ->where('client_id', $client->id);

                if ($status !== 'all' && ! $includeInactive) {
                    $mine->where('status', Template::STATUS_ACTIVE);
                }
            });
        })->orderByRaw("CASE WHEN scope = 'client' THEN 0 ELSE 1 END")
            ->latest('id');

        return $this->applyFilters($query, $filters, allowStatus: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Template $template, ?Client $viewer = null): array
    {
        $isGlobal = $template->isGlobal();
        $isMine = $viewer ? $template->isOwnedByClient($viewer->id) : false;

        return [
            'id' => $template->id,
            'template_id' => $template->id,
            'scope' => $template->scope,
            'is_system' => $isGlobal,
            'is_mine' => $isMine,
            'can_edit' => $viewer ? $isMine : $isGlobal,
            'can_delete' => $viewer ? $isMine : $isGlobal,
            'type' => $template->type,
            'occasion_type' => $template->occasion_type,
            'category' => $template->category,
            'name' => $template->name,
            'description' => $template->description,
            'intro' => $template->intro,
            'body' => $template->body,
            'cta' => $template->cta,
            'questions' => $template->questions ?? [],
            'placeholders' => ContactPlaceholders::requiredPlaceholders((string) $template->body),
            'status' => $template->status,
            'created_at' => optional($template->created_at)->toDateTimeString(),
            'updated_at' => optional($template->updated_at)->toDateTimeString(),
        ];
    }

    public function canClientView(Client $client, Template $template): bool
    {
        if ($template->isOwnedByClient($client->id)) {
            return true;
        }

        return $template->isGlobal() && $template->status === Template::STATUS_ACTIVE;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function createByAdmin(Admin $admin, array $input): array
    {
        $attributes = $this->buildAttributes($input, null);

        if (isset($attributes['in_error'])) {
            return $attributes;
        }

        $template = Template::create($attributes + [
            'scope' => Template::SCOPE_GLOBAL,
            'client_id' => null,
            'admin_id' => $admin->id,
            'status' => $input['status'] ?? Template::STATUS_ACTIVE,
        ]);

        $this->activity()->record('Template created', 'template', 'created', 'admin', $admin, null, [
            'template_id' => $template->id,
            'type' => $template->type,
        ]);

        return $this->result(false, 'Template created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($template));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function updateByAdmin(Admin $admin, Template $template, array $input): array
    {
        if (! $template->isGlobal()) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $attributes = $this->buildAttributes($input, $template);

        if (isset($attributes['in_error'])) {
            return $attributes;
        }

        $extra = ['admin_id' => $admin->id];

        if (array_key_exists('status', $input) && in_array($input['status'], Template::STATUSES, true)) {
            $extra['status'] = $input['status'];
        }

        $template->update($attributes + $extra);

        $this->activity()->record('Template updated', 'template', 'updated', 'admin', $admin, null, [
            'template_id' => $template->id,
        ]);

        return $this->result(false, 'Template updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($template->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function setStatusByAdmin(Admin $admin, Template $template, string $status): array
    {
        if (! $template->isGlobal()) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if (! in_array($status, Template::STATUSES, true)) {
            return $this->result(true, 'Invalid template status.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        $isActivating = $status === Template::STATUS_ACTIVE;

        if ($template->status === $status) {
            return $this->result(
                false,
                $isActivating ? 'Template is already active.' : 'Template is already deactivated.',
                ApiCode::SUCCESS,
                ApiReason::SUCCESS,
                $this->present($template),
            );
        }

        $template->update([
            'status' => $status,
            'admin_id' => $admin->id,
        ]);

        $this->activity()->record(
            $isActivating ? 'Template activated' : 'Template deactivated',
            'template',
            'updated',
            'admin',
            $admin,
            null,
            ['template_id' => $template->id, 'status' => $status],
        );

        return $this->result(
            false,
            $isActivating ? 'Template activated.' : 'Template deactivated and hidden from clients.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($template->fresh()),
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function deleteByAdmin(Admin $admin, Template $template): array
    {
        if (! $template->isGlobal()) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $id = $template->id;
        $template->delete();

        $this->activity()->record('Template deleted', 'template', 'deleted', 'admin', $admin, null, [
            'template_id' => $id,
        ]);

        return $this->result(false, 'Template deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function createByClient(Client $client, array $input): array
    {
        $attributes = $this->buildAttributes($input, null);

        if (isset($attributes['in_error'])) {
            return $attributes;
        }

        $template = Template::create($attributes + [
            'scope' => Template::SCOPE_CLIENT,
            'client_id' => $client->id,
            'admin_id' => null,
            'status' => in_array($input['status'] ?? null, Template::STATUSES, true) ? $input['status'] : Template::STATUS_ACTIVE,
        ]);

        $this->activity()->record('Template created', 'template', 'created', 'client', $client, $client, [
            'template_id' => $template->id,
            'type' => $template->type,
        ]);

        return $this->result(false, 'Template created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($template, $client));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function updateByClient(Client $client, Template $template, array $input): array
    {
        if ($template->isGlobal()) {
            return $this->result(true, 'System templates cannot be edited. Create your own copy instead.', ApiCode::FORBIDDEN, ApiReason::FORBIDDEN);
        }

        if (! $template->isOwnedByClient($client->id)) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $attributes = $this->buildAttributes($input, $template);

        if (isset($attributes['in_error'])) {
            return $attributes;
        }

        $extra = [];

        if (array_key_exists('status', $input) && in_array($input['status'], Template::STATUSES, true)) {
            $extra['status'] = $input['status'];
        }

        $template->update($attributes + $extra);

        $this->activity()->record('Template updated', 'template', 'updated', 'client', $client, $client, [
            'template_id' => $template->id,
        ]);

        return $this->result(false, 'Template updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($template->fresh(), $client));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function setStatusByClient(Client $client, Template $template, string $status): array
    {
        if ($template->isGlobal()) {
            return $this->result(true, 'System templates cannot be activated or deactivated by clients.', ApiCode::FORBIDDEN, ApiReason::FORBIDDEN);
        }

        if (! $template->isOwnedByClient($client->id)) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if (! in_array($status, Template::STATUSES, true)) {
            return $this->result(true, 'Invalid template status.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        $isActivating = $status === Template::STATUS_ACTIVE;

        if ($template->status === $status) {
            return $this->result(
                false,
                $isActivating ? 'Template is already active.' : 'Template is already deactivated.',
                ApiCode::SUCCESS,
                ApiReason::SUCCESS,
                $this->present($template, $client),
            );
        }

        $template->update(['status' => $status]);

        $this->activity()->record(
            $isActivating ? 'Template activated' : 'Template deactivated',
            'template',
            'updated',
            'client',
            $client,
            $client,
            ['template_id' => $template->id, 'status' => $status],
        );

        return $this->result(
            false,
            $isActivating ? 'Template activated.' : 'Template deactivated.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($template->fresh(), $client),
        );
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function deleteByClient(Client $client, Template $template): array
    {
        if ($template->isGlobal()) {
            return $this->result(true, 'System templates cannot be deleted.', ApiCode::FORBIDDEN, ApiReason::FORBIDDEN);
        }

        if (! $template->isOwnedByClient($client->id)) {
            return $this->result(true, 'Template not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $id = $template->id;
        $template->delete();

        $this->activity()->record('Template deleted', 'template', 'deleted', 'client', $client, $client, [
            'template_id' => $id,
        ]);

        return $this->result(false, 'Template deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters, bool $allowStatus): Builder
    {
        if (! empty($filters['type'])) {
            $types = array_values(array_filter(array_map('trim', explode(',', (string) $filters['type']))));

            if (count($types) === 1) {
                $query->where('type', $types[0]);
            } elseif ($types !== []) {
                $query->whereIn('type', $types);
            }
        }

        if (! empty($filters['occasion_type'])) {
            $query->where('occasion_type', trim((string) $filters['occasion_type']));
        }

        if (! empty($filters['category'])) {
            $query->where('category', trim((string) $filters['category']));
        }

        if ($allowStatus && ! empty($filters['status'])) {
            $query->where('status', trim((string) $filters['status']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['search'])).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('body', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Validate and normalize type-specific fields (occasion_type, cta, survey questions).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function buildAttributes(array $input, ?Template $existing): array
    {
        $get = function (string $key, mixed $default = null) use ($input, $existing) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }

            return $existing?->{$key} ?? $default;
        };

        $type = (string) $get('type', '');
        $name = trim((string) $get('name', ''));
        $body = trim((string) $get('body', ''));
        $errors = [];

        if (! in_array($type, Template::TYPES, true)) {
            $errors['type'] = ['Allowed: '.implode(', ', Template::TYPES)];
        }

        if ($name === '') {
            $errors['name'] = ['A template name is required.'];
        }

        if ($body === '') {
            $errors['body'] = ['The template message body is required.'];
        }

        $occasionType = null;
        if ($type === Template::TYPE_CAMPAIGN_OCCASION) {
            $occasionType = trim((string) $get('occasion_type', ''));

            if (! in_array($occasionType, CampaignService::OCCASION_TYPES, true)) {
                $errors['occasion_type'] = ['Allowed: '.implode(', ', CampaignService::OCCASION_TYPES)];
            }
        }

        $cta = null;
        if (in_array($type, [Template::TYPE_CAMPAIGN_PROMOTION, Template::TYPE_CAMPAIGN_OCCASION], true)) {
            $rawCta = $get('cta');

            if (is_array($rawCta) && $rawCta !== []) {
                $cta = [
                    'enabled' => filter_var($rawCta['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'type' => $rawCta['type'] ?? 'link',
                    'label' => $rawCta['label'] ?? null,
                    'value' => $rawCta['value'] ?? $rawCta['url'] ?? null,
                    'url' => $rawCta['url'] ?? (($rawCta['type'] ?? null) === 'link' ? ($rawCta['value'] ?? null) : null),
                ];
            }
        }

        $intro = null;
        $questions = null;

        if ($type === Template::TYPE_SURVEY) {
            $intro = $get('intro');
            $rawQuestions = $get('questions', []);

            if (! is_array($rawQuestions) || $rawQuestions === []) {
                $errors['questions'] = ['Survey templates must include at least one question.'];
            } else {
                $questions = [];

                foreach (array_values($rawQuestions) as $i => $q) {
                    $q = is_array($q) ? $q : [];
                    $prompt = trim((string) ($q['prompt'] ?? ''));
                    $qType = (string) ($q['type'] ?? '');
                    $options = null;

                    if ($prompt === '') {
                        $errors["questions.{$i}.prompt"] = ['Question prompt is required.'];
                    }

                    if (! in_array($qType, Survey::QUESTION_TYPES, true)) {
                        $errors["questions.{$i}.type"] = ['Allowed: '.implode(', ', Survey::QUESTION_TYPES)];
                    } elseif (in_array($qType, ['single_choice', 'multiple_choice'], true)) {
                        $options = collect($q['options'] ?? [])
                            ->filter(fn ($opt) => is_scalar($opt) && trim((string) $opt) !== '')
                            ->map(fn ($opt) => trim((string) $opt))
                            ->unique()
                            ->values()
                            ->all();

                        if (count($options) < 2) {
                            $errors["questions.{$i}.options"] = ['Add at least two different options.'];
                        }
                    }

                    $questions[] = [
                        'prompt' => $prompt,
                        'type' => $qType,
                        'options' => $options,
                        'required' => array_key_exists('required', $q) ? filter_var($q['required'], FILTER_VALIDATE_BOOLEAN) : true,
                        'position' => isset($q['position']) && is_numeric($q['position']) ? (int) $q['position'] : $i,
                    ];
                }
            }
        }

        if ($errors !== []) {
            return $this->result(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $errors);
        }

        return [
            'type' => $type,
            'occasion_type' => $occasionType,
            'category' => $get('category') ? trim((string) $get('category')) : null,
            'name' => $name,
            'description' => $get('description') ? trim((string) $get('description')) : null,
            'intro' => $intro,
            'body' => $body,
            'cta' => $cta,
            'questions' => $questions,
        ];
    }
}
