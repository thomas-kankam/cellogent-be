<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Browser landing page after Paystack (and other redirect checkouts).
 * Query: ?reference=&status= — status is set by PaymentWebhookController::paystackReturn.
 */
class PaymentReturnController extends Controller
{
    public function complete(Request $request): View
    {
        $reference = trim((string) $request->query('reference', ''));
        $status = strtolower(trim((string) $request->query('status', 'unknown')));

        $payment = $reference !== ''
            ? Payment::query()->where('reference_id', $reference)->first()
            : null;

        if ($payment) {
            $status = strtolower((string) $payment->status);
        }

        $copy = match (true) {
            in_array($status, ['paid', 'success', 'successful'], true) => [
                'tone' => 'success',
                'title' => 'Payment successful',
                'body' => 'Your payment went through. You can close this tab and return to the app.',
            ],
            in_array($status, ['pending', 'processing'], true) => [
                'tone' => 'pending',
                'title' => 'Payment pending',
                'body' => 'We are still confirming this payment. It usually updates within a minute — check your wallet or orders in the app.',
            ],
            in_array($status, ['failed', 'abandoned', 'cancelled'], true) => [
                'tone' => 'failed',
                'title' => 'Payment failed',
                'body' => 'This payment was not completed. You can try again from the app.',
            ],
            default => [
                'tone' => 'unknown',
                'title' => 'Payment status unknown',
                'body' => 'We could not match this checkout. If you were charged, open the app and check payments — or contact support with the reference below.',
            ],
        };

        $appUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');

        return view('payments.complete', [
            'tone' => $copy['tone'],
            'title' => $copy['title'],
            'body' => $copy['body'],
            'reference' => $reference !== '' ? $reference : ($payment?->reference_id ?? null),
            'status' => $status,
            'amount' => $payment ? (float) $payment->total_amount : null,
            'currency' => $payment?->currency ?? config('payments.currency', 'GHS'),
            'purpose' => $payment?->purpose,
            'appUrl' => $appUrl,
            'brand' => config('app.name', 'Cellogent'),
        ]);
    }
}
