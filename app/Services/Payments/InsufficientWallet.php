<?php

namespace App\Services\Payments;

use Exception;

final class InsufficientWallet extends Exception
{
    public function __construct()
    {
        parent::__construct('Insufficient wallet balance.');
    }
}
