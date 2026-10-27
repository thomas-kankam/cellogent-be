<?php

namespace App\Concerns;

trait ReturnsServiceResult
{
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
