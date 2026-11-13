<?php

/**
 * Interactive API docs catalogue for GET /docs/api.
 * `fixture` keys map to resources/api-docs/fixtures/{name}.json (live) or .example.json.
 */

$envelope = [
    'in_error' => 'boolean — true on failure',
    'status_code' => 'string HTTP-like code ("200","422","503")',
    'message' => 'human-readable summary',
    'reason' => 'machine code (success, validation_error, provider_unavailable, …)',
    'data' => 'object | array — payload (lists are arrays; single resources are flat objects)',
    'pagination' => 'optional — { current_page, per_page, total, last_page }',
    'point_in_time' => 'server timestamp',
];

return [
    'title' => 'Cellogent API Playbook',
    'intro' => 'Frontend reference for every client & admin route. Each feature lists the payloads you can send, what each field means, and a real (or expected) response so you do not need Postman guesswork.',
    'conventions' => [
        'base' => '/api',
        'auth_client' => 'Authorization: Bearer {client_access_token}  (from POST /client/verify_otp)',
        'auth_admin' => 'Authorization: Bearer {admin_access_token}  (from POST /admin/verify_otp)',
        'content_type' => 'application/json (use multipart/form-data when uploading profile_image or contact CSV)',
        'envelope' => $envelope,
        'notes' => [
            'Never trust client-sent prices — quote/purchase always recalculate sell price with margins.',
            'YELLO / plain MTN Affordable SME data_bundle requires verified beneficiaries. Mash Up, AT, TELECEL, airtime, ECG, TV, results do not.',
            'Group utility quotes: amount = unit_amount × verified. SMS still goes to all reachable contacts; rewards/data only to verified when required.',
            'Payment flow: start checkout → open payment_link / approve MoMo → optional POST …/verify → browser may land on /payments/complete.',
        ],
    ],
    'sections' => [
        require __DIR__.'/sections/public.php',
        require __DIR__.'/sections/client.php',
        require __DIR__.'/sections/admin.php',
    ],
];
