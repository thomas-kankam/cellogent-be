<?php

namespace App\Services\Sms;

use App\Support\ApiReason;
use Exception;

final class SmsRejected extends Exception
{
    public function __construct(string $message, public string $reason = ApiReason::INVALID)
    {
        parent::__construct($message);
    }
}
