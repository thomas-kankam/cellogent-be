<?php

namespace App\Http\Requests\ApiKey\Concerns;

use App\Support\ContactDate;

trait NormalizesExpiresAt
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->exists('expires_at')) {
            return;
        }

        $raw = $this->input('expires_at');
        $blank = $raw === null || trim((string) $raw) === '';

        $this->merge([
            'expires_at' => $blank ? null : (ContactDate::store($raw) ?? '__invalid__'),
        ]);
    }
}
