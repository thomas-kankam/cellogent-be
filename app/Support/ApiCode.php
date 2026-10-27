<?php

namespace App\Support;

/**
 * HTTP statuses used by every API response.
 * Controllers and services must pick from this list so clients can rely on a stable contract.
 */
final class ApiCode
{
    public const SUCCESS = 200;

    public const CREATED = 201;

    public const BAD_REQUEST = 400;

    public const UNAUTHENTICATED = 401;

    public const FORBIDDEN = 403;

    public const NOT_FOUND = 404;

    public const VALIDATION = 422;

    public const TOO_MANY = 429;

    public const SERVER = 500;
}
