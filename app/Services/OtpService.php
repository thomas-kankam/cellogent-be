<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Models\Actor;
use App\Models\Client;
use App\Repositories\ActorRepository;
use App\Repositories\OtpRepository;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Traits\AppNotifications;
use App\Traits\Helpers;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Request, resend, and verify a 6-digit email OTP.
 * Controllers only pass guard + email + type + code.
 */
class OtpService
{
    use AppNotifications;
    use Helpers;
    use LogsActivity;

    public function __construct(
        protected OtpRepository $otps,
        protected ActorRepository $actors,
    ) {
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function request(string $guard, string $email, string $type = 'login'): array
    {
        $email = strtolower(trim($email));
        $actor = $this->actors->findByEmail($guard, $email);

        if (! $actor) {
            return $this->result(true, 'Account not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($actor->status !== 'active') {
            return $this->result(true, 'This account is not active.', ApiCode::FORBIDDEN, ApiReason::ACCOUNT_INACTIVE);
        }

        $blocked = $this->unverifiedClientBlock($actor, $type);
        if ($blocked) {
            return $blocked;
        }

        $throttleKey = "otp:{$guard}:{$email}";
        $max = (int) config('otp.max_attempts', 5);
        $decay = (int) config('otp.decay_seconds', 600);

        if (RateLimiter::tooManyAttempts($throttleKey, $max)) {
            return $this->result(
                true,
                'Too many OTP requests. Try again later.',
                ApiCode::TOO_MANY,
                ApiReason::TOO_MANY_REQUESTS,
                ['retry_after' => RateLimiter::availableIn($throttleKey)],
            );
        }

        RateLimiter::hit($throttleKey, $decay);

        return $this->issue($actor, $type);
    }

    /**
     * Same as request: invalidate the previous unused code and send a new one.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function resend(string $guard, string $email, string $type = 'login'): array
    {
        return $this->request($guard, $email, $type);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>, actor?: Actor}
     */
    public function verify(string $guard, string $email, string $plainOtp, string $type = 'login'): array
    {
        $email = strtolower(trim($email));
        $actor = $this->actors->findByEmail($guard, $email);

        if (! $actor) {
            return $this->result(true, 'Account not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $blocked = $this->unverifiedClientBlock($actor, $type);
        if ($blocked) {
            return $blocked;
        }

        $otp = $this->otps->latestOpen($guard, $email, $type);

        if (! $otp) {
            return $this->result(true, 'OTP not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($otp->isExpired()) {
            $otp->update(['used_at' => now()]);

            $this->activity()->forActorSelf($actor, 'OTP expired', 'otp', 'expired', ['type' => $type]);

            return $this->result(true, 'OTP has expired.', ApiCode::BAD_REQUEST, ApiReason::EXPIRED);
        }

        if (! hash_equals($otp->token, $this->hashOtp($plainOtp))) {
            $this->activity()->forActorSelf($actor, 'OTP invalid', 'otp', 'invalid', ['type' => $type]);

            return $this->result(true, 'OTP is invalid.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $otp->update(['used_at' => now()]);

        $this->activity()->forActorSelf($actor, 'OTP verified', 'otp', 'verified', ['type' => $type]);

        $payload = $this->result(false, 'OTP verified.', ApiCode::SUCCESS, ApiReason::VERIFIED);
        $payload['actor'] = $actor;

        return $payload;
    }

    /**
     * Create a hashed OTP, email it on the queue, and log the request.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function issue(Actor $actor, string $type, string $channel = 'email'): array
    {
        $this->otps->invalidateOpen($actor->guardName(), (int) $actor->getKey(), $type);

        $plain = $this->makeOtpCode();
        $minutes = (int) config('otp.expires_minutes', 10);

        $this->otps->create([
            'token' => $this->hashOtp($plain),
            'email' => $actor->email,
            'actor' => $actor->guardName(),
            'actor_id' => $actor->getKey(),
            'type' => $type,
            'channel' => $channel,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $this->notifyOtp(
            $actor->email,
            $plain,
            $type,
            trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: null,
        );

        $this->activity()->forActorSelf($actor, 'OTP requested', 'otp', 'requested', [
            'type' => $type,
            'channel' => $channel,
        ]);

        $data = [
            'email' => $actor->email,
            'channel' => $channel,
            'expires_at' => now()->addMinutes($minutes)->toDateTimeString(),
        ];

        // Local-only helper so you can finish Postman without opening the mailbox.
        if (app()->environment('local') && config('otp.expose_in_local')) {
            $data['debug_otp'] = $plain;
        }

        return $this->result(false, 'OTP sent.', ApiCode::SUCCESS, ApiReason::OTP_SENT, $data);
    }

    /**
     * Unverified clients may only use the registration OTP until they confirm signup.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}|null
     */
    protected function unverifiedClientBlock(Actor $actor, string $type): ?array
    {
        if ($actor instanceof Client && ! $actor->is_verified && $type !== 'registration') {
            return $this->result(
                true,
                'Verify the registration OTP sent to your email first.',
                ApiCode::FORBIDDEN,
                ApiReason::UNVERIFIED,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function result(bool $inError, string $message, int $status, string $reason, array $data = []): array
    {
        return [
            'in_error' => $inError,
            'message' => $message,
            'status_code' => $status,
            'reason' => $reason,
            'data' => $data,
        ];
    }
}
