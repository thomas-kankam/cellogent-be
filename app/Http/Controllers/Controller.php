<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Traits\ApiTransformer;

abstract class Controller
{
    use ApiTransformer;

    /**
     * Turn a service array {in_error, message, status_code, reason, data} into JSON.
     *
     * @param  array{in_error: bool, message: string, status_code: int, reason: string, data?: array<string, mixed>}  $result
     */
    protected function respondFrom(array $result)
    {
        return ApiResponse::make(
            $result['in_error'],
            $result['message'],
            $result['status_code'],
            $result['reason'],
            $result['data'] ?? [],
        );
    }
}
