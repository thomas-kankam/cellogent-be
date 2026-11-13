<?php

namespace App\Http\Controllers;

use App\Services\Utilities\UtilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Swift order notifications (order.fulfilled / order.failed). When SWIFT_SECRET_KEY is set the body
 * must carry a valid X-Swift-Signature (HMAC-SHA256 of the raw body); otherwise events are still
 * accepted but only ever move our own pending order to the state Swift reports.
 */
class SwiftWebhookController extends Controller
{
    public function __construct(protected UtilityService $utilities) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('swift.secret_key');

        // Outside local/testing, unsigned webhooks are rejected so strangers cannot flip order status.
        if ($secret === '' && ! app()->environment(['local', 'testing'])) {
            Log::warning('Swift webhook rejected: SWIFT_SECRET_KEY is not set.');

            return response()->json(['ok' => false], 401);
        }

        if ($secret !== '') {
            $given = (string) $request->header('X-Swift-Signature', '');
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            // Some senders prefix the digest with "sha256=".
            $given = str_starts_with($given, 'sha256=') ? substr($given, 7) : $given;

            if ($given === '' || ! hash_equals($expected, strtolower($given))) {
                Log::warning('Swift webhook rejected: bad signature.');

                return response()->json(['ok' => false], 401);
            }
        }

        $payload = $request->json()->all() ?: $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['ok' => false, 'message' => 'Empty payload.'], 400);
        }

        // The order may sit under "data" or "order" depending on the event.
        if (! isset($payload['order_id'])) {
            foreach (['data', 'order'] as $wrapper) {
                if (is_array($payload[$wrapper] ?? null)) {
                    $inner = $payload[$wrapper];
                    $payload = ['event' => $payload['event'] ?? null, 'order_id' => $inner['order_id'] ?? $inner['id'] ?? null] + $inner + $payload;

                    break;
                }
            }
        }

        $result = $this->utilities->handleWebhook($payload);

        // Always 200 for events we understood (even unknown orders) so Swift does not retry forever.
        return response()->json(['ok' => ! $result['in_error'], 'message' => $result['message']], $result['in_error'] ? 422 : 200);
    }
}
