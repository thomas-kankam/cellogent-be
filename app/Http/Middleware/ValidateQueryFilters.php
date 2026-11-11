<?php

namespace App\Http\Middleware;

use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * List filters (?status=, ?type=, ?search=, dates) are read as strings / dates. `?status[]=x` or
 * `?from=garbage` would otherwise blow up as a 500, so reject them up front as a 422.
 */
class ValidateQueryFilters
{
    public function handle(Request $request, Closure $next): Response
    {
        $errors = [];

        foreach ($request->query->all() as $key => $value) {
            if (is_array($value)) {
                $errors[$key] = ['This filter must be a single value.'];
            } elseif (in_array($key, self::DATE_KEYS, true) && $value !== '' && ! $this->isDate((string) $value)) {
                $errors[$key] = ['Use a valid date, for example 2026-01-31.'];
            }
        }

        if ($errors !== []) {
            return ApiResponse::make(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $errors);
        }

        return $next($request);
    }

    /** Filters read with $request->date(), which throws on unparsable input. */
    protected const DATE_KEYS = ['from', 'to', 'from_date', 'to_date'];

    protected function isDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
