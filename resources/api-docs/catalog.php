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
            'Swift data/airtime is 1 phone per API call. Our bulk creates N local orders (≤50 for CSV/items; up to group_purchase_max for contact groups) and queues them with spacing for Swift’s ~30 req/min limit.',
            'Bulk tracking: purchase returns batch_id + analytics; poll GET /client/utilities/batches/{batch_id} (admin: /admin/utilities/batches/{batch}). Statuses update from jobs + Swift webhooks.',
            'Recipient shapes are mutually exclusive: beneficiary | beneficiaries | items[] | contact_group_ids.',
            'Payment flow: method=wallet debits now; momo/card → Paystack/Kairos checkout → payment_link → verify/webhook → orders queued. Browser may land on /payments/complete.',
        ],
    ],
    'sections' => [
        require __DIR__.'/sections/public.php',
        require __DIR__.'/sections/client.php',
        require __DIR__.'/sections/admin.php',
    ],
];
