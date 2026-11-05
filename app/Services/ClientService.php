<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Http\Requests\Concerns\ValidatesClientAccount;
use App\Jobs\ProcessProfileImageJob;
use App\Models\Admin;
use App\Models\ApiKey;
use App\Models\Client;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Http\UploadedFile;

class ClientService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public const STATUSES = ['active', 'inactive'];

    public function __construct(protected ProfilePresenter $profiles) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Client $client): array
    {
        $client->loadMissing(['wallet', 'subscription', 'onboardedBy']);

        $payload = $this->profiles->present($client);

        $payload['onboarding_by'] = $client->onboardedBy ? [
            'id' => $client->onboardedBy->id,
            'first_name' => $client->onboardedBy->first_name,
            'last_name' => $client->onboardedBy->last_name,
            'email' => $client->onboardedBy->email,
        ] : null;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $actor, Client $client, array $input, ?UploadedFile $image = null): array
    {
        if (isset($input['email'])) {
            $email = strtolower(trim((string) $input['email']));
            $taken = Client::query()->where('email', $email)->whereKeyNot($client->id)->exists();

            if ($taken) {
                return $this->result(true, 'This email is already registered.', ApiCode::BAD_REQUEST, ApiReason::EMAIL_TAKEN);
            }

            $input['email'] = $email;
        }

        if (array_key_exists('phone_number', $input)) {
            $input['phone_number'] = GhanaPhone::normalize($input['phone_number']);
        }

        if (isset($input['account_type'])) {
            $input = ValidatesClientAccount::normalizeClientAccountPayload($input);
        } elseif (($client->account_type ?? null) === 'individual') {
            $input = ValidatesClientAccount::normalizeClientAccountPayload($input + ['account_type' => 'individual']);
        }

        $fields = [
            'first_name', 'last_name', 'email', 'phone_number', 'date_of_birth', 'gender',
            'account_type', 'business_name', 'region', 'city', 'address',
        ];

        $before = $client->only($fields);
        $client->fill(collect($input)->only($fields)->all())->save();

        if ($image) {
            $client->storeProfileImage($image, 'client');
            ProcessProfileImageJob::dispatch('client', (int) $client->id, (string) $client->profile_image);
        }

        $this->activity()->record('Client updated', 'client', 'updated', 'admin', $actor, $client, [
            'before' => $before,
            'after' => $client->only($fields),
        ]);

        return $this->result(false, 'Client updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($client->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function updateStatus(Admin $actor, Client $client, string $status): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            return $this->result(true, 'Status must be active or inactive.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'status' => ['Status must be active or inactive.'],
            ]);
        }

        if ($client->status === $status) {
            return $this->result(false, 'Client status unchanged.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($client));
        }

        $client->update(['status' => $status]);

        if ($status !== 'active') {
            $this->revokeAccess($client);
        }

        $this->activity()->record('Client status updated', 'account_status', 'updated', 'admin', $actor, $client, [
            'status' => $status,
        ]);

        return $this->result(false, 'Client status updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($client->fresh()));
    }

    protected function revokeAccess(Client $client): void
    {
        $client->tokens()->get()->each->revoke();

        ApiKey::query()
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);
    }
}
