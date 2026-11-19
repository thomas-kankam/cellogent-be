<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ClientSignupRequest;
use App\Models\Client;
use App\Services\AuthTokenService;
use App\Services\OnboardingService;
use App\Services\OtpService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAuthController extends PasswordlessAuthController
{
    public function __construct(
        OtpService $otp,
        AuthTokenService $tokens,
        protected OnboardingService $onboarding,
    ) {
        parent::__construct($otp, $tokens);
    }

    protected function guardName(): string
    {
        return 'client';
    }

    /**
     * Client self-onboarding.
     * Validation lives in ClientSignupRequest (rules depend on account_type).
     * Persistence and registration OTP live in OnboardingService.
     * Wallet, trial, and archive are created only after verify_otp (type=registration).
     */
    public function signup(ClientSignupRequest $request): JsonResponse
    {
        return $this->respondFrom($this->onboarding->selfOnboardClient(
            $request->validated(),
            $request->file('profile_image'),
        ));
    }

    /**
     * Check whether `emailOrPhone` already exists in the `clients` table before signup.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $value = (string) ($request->input('emailOrPhone')
            ?? $request->input('email_or_phone')
            ?? $request->input('email')
            ?? $request->input('phone_number')
            ?? '');

        if (trim($value) === '') {
            return $this->apiResponse(true, 'Please provide an email or phone number.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'emailOrPhone' => ['The emailOrPhone field is required.'],
            ]);
        }

        return $this->respondFrom($this->onboarding->checkClientAvailability($value));
    }

    /**
     * First login must be the registration OTP. That is when the trial pack is granted.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}|null
     */
    protected function afterOtpVerified($actor, string $type): ?array
    {
        if (! $actor instanceof Client) {
            return null;
        }

        if (! $actor->is_verified && $type !== 'registration') {
            return [
                'in_error' => true,
                'message' => 'OTP is invalid.',
                'status_code' => ApiCode::BAD_REQUEST,
                'reason' => ApiReason::INVALID,
                'data' => [],
            ];
        }

        if (! $actor->is_verified) {
            $this->onboarding->completeClientVerification($actor);
        }

        return null;
    }
}
