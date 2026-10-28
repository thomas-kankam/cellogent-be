<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\AuthTokenService;
use App\Services\OtpService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared OTP request / resend / verify for both client and admin.
 * Child classes only declare which Passport guard they belong to.
 */
abstract class PasswordlessAuthController extends Controller
{
    public function __construct(
        protected OtpService $otp,
        protected AuthTokenService $tokens,
    ) {
    }

    abstract protected function guardName(): string;

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        return $this->respondFrom($this->otp->request(
            $this->guardName(),
            $request->string('email')->toString(),
            $request->input('type', 'login'),
        ));
    }

    public function resendOtpRequest(RequestOtpRequest $request): JsonResponse
    {
        return $this->respondFrom($this->otp->resend(
            $this->guardName(),
            $request->string('email')->toString(),
            $request->input('type', 'login'),
        ));
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->otp->verify(
            $this->guardName(),
            $request->string('email')->toString(),
            $request->string('otp')->toString(),
            $request->input('type', 'login'),
        );

        if (($result['reason'] ?? null) !== ApiReason::VERIFIED || empty($result['actor'])) {
            return $this->respondFrom($result);
        }

        if ($result['actor']->status !== 'active') {
            $result['in_error'] = true;
            $result['status_code'] = 403;
            $result['reason'] = 'account_inactive';
            $result['message'] = 'This account is not active.';
            $result['data'] = [];

            return $this->respondFrom($result);
        }

        $after = $this->afterOtpVerified(
            $result['actor'],
            $request->input('type', 'login'),
        );

        if ($after !== null) {
            return $this->respondFrom($after);
        }

        $result['data'] = $this->tokens->login($result['actor']->fresh());
        $result['message'] = 'Login successful.';

        return $this->respondFrom($result);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}|null
     */
    protected function afterOtpVerified($actor, string $type): ?array
    {
        return null;
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken() ?? $user?->token();
        $token?->revoke();

        return $this->apiResponse(false, 'Logged out.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }
}
