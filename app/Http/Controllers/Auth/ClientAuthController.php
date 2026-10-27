<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ClientSignupRequest;
use App\Services\AuthTokenService;
use App\Services\OnboardingService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

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
     * Persistence, referral code, wallet, OTP, and logs live in OnboardingService.
     * No Passport token is issued here — that happens on verify_otp.
     */
    public function signup(ClientSignupRequest $request): JsonResponse
    {
        return $this->respondFrom($this->onboarding->selfOnboardClient(
            $request->validated(),
            $request->file('profile_image'),
        ));
    }
}
