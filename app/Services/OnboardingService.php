<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Repositories\ActorRepository;
use App\Repositories\AdminRepository;
use App\Repositories\ClientRepository;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Traits\AppNotifications;
use App\Traits\Helpers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    use AppNotifications;
    use Helpers;
    use LogsActivity;

    public function __construct(
        protected ActorRepository $actors,
        protected ClientRepository $clients,
        protected AdminRepository $admins,
        protected OtpService $otp,
        protected ProfilePresenter $profiles,
    ) {
    }

    /**
     * Client creates their own account, then receives a registration OTP.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function selfOnboardClient(array $input, ?UploadedFile $image = null): array
    {
        $email = strtolower(trim($input['email']));

        if ($this->actors->emailTaken('client', $email)) {
            return $this->fail('This email is already registered.', ApiCode::BAD_REQUEST, ApiReason::EMAIL_TAKEN);
        }

        $client = DB::transaction(function () use ($input, $email, $image) {
            $client = $this->clients->create([
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'email' => $email,
                'phone_number' => $input['phone_number'] ?? null,
                'date_of_birth' => $input['date_of_birth'] ?? null,
                'gender' => $input['gender'] ?? null,
                'account_type' => $input['account_type'] ?? null,
                'business_name' => $input['business_name'] ?? null,
                'region' => $input['region'] ?? null,
                'city' => $input['city'] ?? null,
                'address' => $input['address'] ?? null,
                'status' => 'active',
                'onboarding_type' => 'self',
                'referral_code' => $this->uniqueReferralCode($input),
            ]);

            $this->provisionClient($client);

            if ($image) {
                $client->storeProfileImage($image, 'client');
            }

            return $client;
        });

        $this->activity()->record(
            'Client self onboarding',
            'onboarding',
            'created',
            'client',
            $client,
            $client,
            ['type' => 'self'],
        );

        $otp = $this->otp->issue($client, 'registration');

        return [
            'in_error' => false,
            'message' => 'Account created. OTP sent to your email.',
            'status_code' => ApiCode::CREATED,
            'reason' => ApiReason::CREATED,
            'data' => [
                'account' => $this->profiles->present($client->fresh()),
                'otp' => $otp['data'],
            ],
        ];
    }

    /**
     * An authenticated admin creates a client on their behalf.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function adminOnboardClient(Admin $admin, array $input, ?UploadedFile $image = null): array
    {
        $email = strtolower(trim($input['email']));

        if ($this->actors->emailTaken('client', $email)) {
            return $this->fail('This email is already registered.', ApiCode::BAD_REQUEST, ApiReason::EMAIL_TAKEN);
        }

        $client = DB::transaction(function () use ($admin, $input, $email, $image) {
            $client = $this->clients->create([
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'email' => $email,
                'phone_number' => $input['phone_number'] ?? null,
                'date_of_birth' => $input['date_of_birth'] ?? null,
                'gender' => $input['gender'] ?? null,
                'account_type' => $input['account_type'] ?? null,
                'business_name' => $input['business_name'] ?? null,
                'region' => $input['region'] ?? null,
                'city' => $input['city'] ?? null,
                'address' => $input['address'] ?? null,
                'status' => 'active',
                'onboarding_type' => 'admin',
                'onboarding_by' => $admin->id,
                'referral_code' => $this->uniqueReferralCode($input),
            ]);

            $this->provisionClient($client);

            if ($image) {
                $client->storeProfileImage($image, 'client');
            }

            return $client;
        });

        $this->activity()->record(
            'Admin onboarded a client',
            'onboarding',
            'created',
            'admin',
            $admin,
            $client,
            ['type' => 'admin'],
        );

        $this->notifyWelcome($client->email, 'client_onboarded', trim($client->first_name.' '.$client->last_name) ?: null);
        $otp = $this->otp->issue($client, 'registration');

        return [
            'in_error' => false,
            'message' => 'Client onboarded. OTP sent to their email.',
            'status_code' => ApiCode::CREATED,
            'reason' => ApiReason::CREATED,
            'data' => [
                'account' => $this->profiles->present($client->fresh()),
                'otp' => $otp['data'],
            ],
        ];
    }

    /**
     * An authenticated admin creates another admin and assigns an existing role.
     *
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function adminOnboardAdmin(Admin $admin, array $input, ?UploadedFile $image = null): array
    {
        $email = strtolower(trim($input['email']));

        if ($this->actors->emailTaken('admin', $email)) {
            return $this->fail('This email is already registered.', ApiCode::BAD_REQUEST, ApiReason::EMAIL_TAKEN);
        }

        $newAdmin = DB::transaction(function () use ($admin, $input, $email, $image) {
            $newAdmin = $this->admins->create([
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'email' => $email,
                'phone_number' => $input['phone_number'] ?? null,
                'status' => 'active',
                'role_id' => $input['role_id'],
                'onboarding_type' => 'admin',
                'onboarding_by' => $admin->id,
            ]);

            if ($image) {
                $newAdmin->storeProfileImage($image, 'admin');
            }

            return $newAdmin;
        });

        $this->activity()->record(
            'Admin onboarded another admin',
            'admin',
            'created',
            'admin',
            $admin,
            $newAdmin,
            ['role_id' => $input['role_id']],
        );

        $this->activity()->record(
            'Role assigned during admin onboarding',
            'role',
            'assigned',
            'admin',
            $admin,
            $newAdmin,
            ['role_id' => $input['role_id']],
        );

        $this->notifyWelcome($newAdmin->email, 'admin_onboarded', trim($newAdmin->first_name.' '.$newAdmin->last_name) ?: null);
        $otp = $this->otp->issue($newAdmin, 'registration');

        return [
            'in_error' => false,
            'message' => 'Admin onboarded. OTP sent to their email.',
            'status_code' => ApiCode::CREATED,
            'reason' => ApiReason::CREATED,
            'data' => [
                'account' => $this->profiles->present($newAdmin->fresh('role.permissions')),
                'otp' => $otp['data'],
            ],
        ];
    }

    protected function provisionClient(Client $client, ?string $incomingReferralCode = null): void
    {
        Wallet::create([
            'client_id' => $client->id,
            'balance' => 0,
            'currency' => 'GHS',
            'status' => 'active',
        ]);

        $plan = Plan::query()->where('name', 'Bronze')->where('status', 'active')->first();
        $months = $plan?->months() ?? 1;

        Subscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name ?? 'Bronze',
            'status' => 'active',
            'units' => $plan?->units() ?? 0,
            'starts_at' => now(),
            'ends_at' => now()->addMonths($months),
            'type' => 'monthly',
            'duration_in_months' => $months,
        ]);

        if ($incomingReferralCode) {
            $referrer = $this->clients->findByReferralCode($incomingReferralCode);

            if ($referrer && $referrer->id !== $client->id) {
                Referral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $client->id,
                    'code' => $incomingReferralCode,
                    'status' => 'active',
                ]);

                $this->activity()->record(
                    'Referral captured',
                    'referral',
                    'created',
                    'client',
                    $referrer,
                    $client,
                    ['code' => $incomingReferralCode],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function uniqueReferralCode(array $input): string
    {
        $source = ($input['account_type'] ?? '') === 'business'
            ? (string) ($input['business_name'] ?? '')
            : trim(($input['first_name'] ?? '').' '.($input['last_name'] ?? ''));

        do {
            $code = $this->makeReferralCode($source);
        } while ($this->clients->findByReferralCode($code));

        return $code;
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function fail(string $message, int $status, string $reason): array
    {
        return [
            'in_error' => true,
            'message' => $message,
            'status_code' => $status,
            'reason' => $reason,
            'data' => [],
        ];
    }
}
