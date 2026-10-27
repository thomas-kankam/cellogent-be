<?php

namespace App\Traits;

use App\Models\Actor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Small reusable helpers: OTP digits, Passport tokens, file uploads, referral codes.
 */
trait Helpers
{
    /**
     * Six-digit numeric OTP, always left-padded (e.g. 004281).
     */
    protected function makeOtpCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * One-way hash so a leaked otps row is not the raw code.
     */
    protected function hashOtp(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    /**
     * Issue a Passport personal-access token for the given guard's provider.
     *
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    protected function issueApiToken(Actor $actor, string $tokenName): array
    {
        $result = $actor->createToken($tokenName);

        return [
            'access_token' => $result->accessToken,
            'token_type' => $result->tokenType ?? 'Bearer',
            'expires_in' => $result->expiresIn ?? 0,
        ];
    }

    /**
     * Store an uploaded image on the public disk. Returns the public URL.
     * Processing (move + unique name) happens here; a job can resize later.
     */
    protected function storePublicImage(UploadedFile $file, string $directory): string
    {
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $name, 'public');

        return Storage::disk('public')->url($path);
    }

    /**
     * 6-character unique-looking code: letters from the name/business, then random alphanumerics.
     * "Ama Foods" -> AMF4K2, "Kojo Asante" -> KOA9B1
     */
    protected function makeReferralCode(string $source = ''): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $source));
        $prefix = substr($letters, 0, 3);
        $suffixLength = 6 - strlen($prefix);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';

        for ($i = 0; $i < $suffixLength; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $code = substr($prefix.$suffix, 0, 6);

        return str_pad($code, 6, $alphabet[random_int(0, strlen($alphabet) - 1)]);
    }
}
