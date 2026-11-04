<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;

interface PaymentGatewayDriver
{
    public function code(): string;

    /**
     * Credentials are present, so the gateway can be offered.
     */
    public function configured(): bool;

    /**
     * Start collecting $payment->total_amount (momo prompt or hosted checkout).
     */
    public function initiate(Payment $payment): GatewayResult;

    public function status(Payment $payment): GatewayStatus;
}
