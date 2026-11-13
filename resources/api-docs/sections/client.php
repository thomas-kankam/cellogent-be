<?php

return [
    'id' => 'client',
    'title' => 'Client',
    'blurb' => 'Dashboard APIs. Auth: Bearer client token unless noted. Account must be active.',
    'groups' => [
        [
            'id' => 'client-auth',
            'title' => 'Auth & profile',
            'endpoints' => [
                [
                    'method' => 'POST',
                    'path' => '/client/signup',
                    'auth' => 'none',
                    'summary' => 'Creates unverified client. Registration OTP emailed. No wallet yet.',
                    'types' => [
                        ['field' => 'account_type', 'type' => "'individual'|'business'", 'notes' => 'required'],
                        ['field' => 'email', 'type' => 'string', 'notes' => 'required'],
                        ['field' => 'phone_number', 'type' => 'string', 'notes' => 'Ghana MSISDN'],
                        ['field' => 'profile_image', 'type' => 'file', 'notes' => 'required for business (multipart)'],
                    ],
                    'scenarios' => [
                        [
                            'id' => 'individual',
                            'title' => 'Individual signup',
                            'explain' => 'Required: first_name, last_name, date_of_birth, email, phone_number, account_type.',
                            'request' => [
                                'account_type' => 'individual',
                                'first_name' => 'Ama',
                                'last_name' => 'Mensah',
                                'email' => 'ama@example.com',
                                'phone_number' => '0244000000',
                                'date_of_birth' => '1994-05-12',
                                'gender' => 'female',
                            ],
                            'response' => [
                                'in_error' => false,
                                'status_code' => '201',
                                'message' => 'Account created. Check your email for the OTP.',
                                'reason' => 'created',
                                'data' => ['id' => 10, 'email' => 'ama@example.com', 'is_verified' => false],
                            ],
                        ],
                        [
                            'id' => 'business',
                            'title' => 'Business signup (multipart)',
                            'explain' => 'Use form-data. Required: business_name, region, city, address, profile_image, email, phone_number.',
                            'request' => [
                                'account_type' => 'business',
                                'email' => 'biz@example.com',
                                'phone_number' => '0244000000',
                                'business_name' => 'Ama Foods',
                                'region' => 'Greater Accra',
                                'city' => 'Accra',
                                'address' => '12 Ring Road',
                                'profile_image' => '(file jpg/png/webp ≤2MB)',
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/request_otp',
                    'auth' => 'none',
                    'scenarios' => [
                        [
                            'id' => 'login',
                            'title' => 'Request login OTP',
                            'request' => ['email' => 'ama@example.com', 'type' => 'login'],
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'OTP sent.',
                                'reason' => 'otp_sent',
                                'data' => ['email' => 'ama@example.com', 'debug_otp' => '482193'],
                            ],
                            'note' => 'debug_otp only appears when APP_ENV=local.',
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/verify_otp',
                    'auth' => 'none',
                    'summary' => 'Returns access_token + profile. Registration also opens wallet + trial units.',
                    'scenarios' => [
                        [
                            'id' => 'registration',
                            'title' => 'Verify registration',
                            'request' => ['email' => 'ama@example.com', 'otp' => '482193', 'type' => 'registration'],
                        ],
                        [
                            'id' => 'login',
                            'title' => 'Verify login',
                            'request' => ['email' => 'ama@example.com', 'otp' => '482193', 'type' => 'login'],
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'OTP verified.',
                                'reason' => 'verified',
                                'data' => [
                                    'id' => 3,
                                    'email' => 'ama@example.com',
                                    'access_token' => 'eyJ…',
                                    'token_type' => 'Bearer',
                                    'expires_at' => '2026-12-13 13:00:00',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/profile',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'me', 'title' => 'Current profile', 'fixture' => 'client_profile']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/logout',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'logout', 'title' => 'Revoke token', 'request' => new stdClass, 'response' => ['in_error' => false, 'message' => 'Logged out.', 'reason' => 'success', 'data' => []]]],
                ],
            ],
        ],

        [
            'id' => 'client-wallet-sub',
            'title' => 'Wallet & subscription',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/wallet',
                    'auth' => 'client',
                    'summary' => 'available = balance − reserved (campaign/survey holds).',
                    'scenarios' => [['id' => 'show', 'title' => 'Wallet balance', 'fixture' => 'client_wallet']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/wallet/top-ups',
                    'auth' => 'client',
                    'scenarios' => [
                        [
                            'id' => 'momo',
                            'title' => 'Top up via MoMo',
                            'request' => ['amount' => 100, 'method' => 'momo', 'phone_number' => '0244123456', 'network' => 'MTN'],
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'Top-up started.',
                                'reason' => 'success',
                                'data' => [
                                    'payment' => [
                                        'id' => 55,
                                        'status' => 'pending',
                                        'payment_link' => 'https://checkout.paystack.com/…',
                                        'total_amount' => 100,
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'card',
                            'title' => 'Top up via card',
                            'request' => ['amount' => 50, 'method' => 'card'],
                        ],
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/subscription',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'show', 'title' => 'Active subscription', 'fixture' => 'client_subscription']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/subscription',
                    'auth' => 'client',
                    'summary' => 'method optional — omit so Paystack shows card+momo. months=0 = non-expiry package.',
                    'scenarios' => [
                        [
                            'id' => 'wallet',
                            'title' => 'Pay with wallet',
                            'request' => ['plan_id' => 2, 'months' => 1, 'method' => 'wallet'],
                        ],
                        [
                            'id' => 'hosted',
                            'title' => 'Hosted checkout (method omitted)',
                            'request' => ['plan_id' => 2, 'months' => 1, 'phone_number' => '0244123456'],
                        ],
                        [
                            'id' => 'non_expiry',
                            'title' => 'Non-expiry package',
                            'request' => ['plan_id' => 2, 'months' => 0, 'method' => 'momo', 'phone_number' => '0244123456', 'network' => 'MTN'],
                        ],
                    ],
                ],
            ],
        ],

        [
            'id' => 'client-payments',
            'title' => 'Payments & orders',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/payments',
                    'auth' => 'client',
                    'summary' => 'Query: status, purpose, from, to, page, per_page.',
                    'scenarios' => [['id' => 'list', 'title' => 'List payments', 'fixture' => 'client_payments']],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/payments/quote',
                    'auth' => 'client',
                    'summary' => 'Preview amount+charge before checkout. purpose ∈ wallet_top_up, sms_units, campaign_fund, survey_fund, data_bundle, airtime, ecg, gotv, dstv, starttimes, mashup, results, utility_bulk.',
                    'scenarios' => [
                        [
                            'id' => 'sms_units',
                            'title' => 'Quote SMS units',
                            'request' => ['purpose' => 'sms_units', 'method' => 'momo', 'plan_id' => 2, 'months' => 1],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/payments/checkout',
                    'auth' => 'client',
                    'summary' => 'Generic checkout. Prefer dedicated wallet / subscription / utilities/purchase when available.',
                    'scenarios' => [
                        [
                            'id' => 'wallet_top_up',
                            'title' => 'Checkout wallet top-up',
                            'request' => ['purpose' => 'wallet_top_up', 'amount' => 50, 'method' => 'card'],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/payments/{payment}/verify',
                    'auth' => 'client',
                    'summary' => '“I have paid” — verifies with gateway now.',
                    'scenarios' => [
                        [
                            'id' => 'verify',
                            'title' => 'Verify pending payment',
                            'request' => new stdClass,
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'Payment successful.',
                                'reason' => 'success',
                                'data' => ['id' => 10, 'status' => 'paid', 'total_amount' => 50],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        [
            'id' => 'client-utilities',
            'title' => 'Utilities (data, mashup, airtime, bills)',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/utilities/catalog',
                    'auth' => 'client',
                    'summary' => 'Live Swift catalogue with sell prices. Plans include requires_beneficiary for YELLO SME. Swift has no multi-phone data API — each recipient becomes one local order.',
                    'scenarios' => [['id' => 'catalog', 'title' => 'Full catalogue', 'fixture' => 'client_utilities_catalog']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/utilities/quote',
                    'auth' => 'client',
                    'summary' => 'Price preview only. Pick ONE recipient shape: single fields, beneficiaries (CSV/array ≤50), items[] (≤50 mixed), or contact_group_ids. Do not combine them.',
                    'types' => [
                        ['field' => 'service', 'type' => 'data|mashup|airtime|ecg|tv|results', 'notes' => 'required unless items[]'],
                        ['field' => 'package_id', 'type' => 'string', 'notes' => 'data/mashup from catalog'],
                        ['field' => 'network', 'type' => 'string', 'notes' => 'airtime: MTN|TELECEL|AT|GLO'],
                        ['field' => 'amount', 'type' => 'number', 'notes' => 'airtime/ecg/tv face value'],
                        ['field' => 'bill_type', 'type' => 'DSTV|GOTV|STARTIMES', 'notes' => 'tv only'],
                        ['field' => 'checker_type', 'type' => 'WASSCE|BECE', 'notes' => 'results'],
                        ['field' => 'quantity', 'type' => 'integer 1–100', 'notes' => 'results vouchers'],
                        ['field' => 'beneficiary', 'type' => 'string', 'notes' => 'single recipient (optional on quote)'],
                        ['field' => 'beneficiaries', 'type' => 'string CSV | string[] ≤50', 'notes' => 'same package to many phones'],
                        ['field' => 'items[]', 'type' => 'array≤50', 'notes' => 'mixed services; each needs its own beneficiary'],
                        ['field' => 'contact_group_ids[]', 'type' => 'int[]', 'notes' => 'data/mashup only; YELLO uses verified contacts'],
                    ],
                    'scenarios' => [
                        [
                            'id' => 'data_single',
                            'title' => '1) Single YELLO data pack',
                            'explain' => 'Unit sell price after margin. If Swift is down → reason=provider_unavailable (503).',
                            'fixture' => 'quote_data_single_success.example',
                        ],
                        [
                            'id' => 'data_group',
                            'title' => '2) contact_group_ids (verified × unit)',
                            'explain' => 'amount = unit_amount × verified. Only verified MTN beneficiaries count for YELLO SME.',
                            'fixture' => 'quote_data_group_success.example',
                        ],
                        [
                            'id' => 'airtime_csv',
                            'title' => '3) Airtime to CSV beneficiaries (live)',
                            'explain' => 'beneficiaries: "0244…,0201…" or ["0244…","0201…"]. amount is per number; total = unit × count.',
                            'fixture' => 'quote_airtime_beneficiaries',
                        ],
                        [
                            'id' => 'airtime',
                            'title' => '4) Single airtime (live)',
                            'fixture' => 'quote_airtime',
                        ],
                        [
                            'id' => 'ecg',
                            'title' => '5) ECG quote (live)',
                            'fixture' => 'quote_ecg',
                        ],
                        [
                            'id' => 'tv',
                            'title' => '6) DSTV quote (live)',
                            'fixture' => 'quote_tv',
                        ],
                        [
                            'id' => 'results',
                            'title' => '7) Results checkers × quantity (live)',
                            'fixture' => 'quote_results',
                        ],
                        [
                            'id' => 'bulk',
                            'title' => '8) Bulk mixed items[]',
                            'fixture' => 'quote_bulk_mixed_success.example',
                        ],
                        [
                            'id' => 'mutual',
                            'title' => '9) Error: mixing beneficiary + beneficiaries',
                            'fixture' => 'quote_mutual_exclusion',
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/utilities/lookup',
                    'auth' => 'client',
                    'summary' => 'Validate ECG meter or TV smartcard → account_name before pay.',
                    'scenarios' => [
                        [
                            'id' => 'ecg',
                            'title' => 'Lookup ECG meter',
                            'request' => ['service' => 'ecg', 'beneficiary' => '1234567890'],
                        ],
                        [
                            'id' => 'tv',
                            'title' => 'Lookup DSTV smartcard',
                            'request' => ['service' => 'tv', 'beneficiary' => '7012345678', 'bill_type' => 'DSTV'],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/utilities/purchase',
                    'auth' => 'client',
                    'summary' => 'Same recipient shapes as quote + method=wallet|momo|card. Wallet debits immediately and queues Swift jobs (staggered ~2s apart for rate limits). MoMo/card starts Paystack/Kairos checkout; after payment, orders are created and queued. Bulk responses include batch_id + analytics — poll GET /utilities/batches/{batch_id}.',
                    'scenarios' => [
                        [
                            'id' => 'missing',
                            'title' => 'Validation: missing recipient (live)',
                            'fixture' => 'purchase_missing_beneficiary',
                        ],
                        [
                            'id' => 'wallet_data',
                            'title' => 'Single data (wallet)',
                            'explain' => 'YELLO numbers are beneficiary-checked before debit.',
                            'request' => [
                                'service' => 'data',
                                'package_id' => 'yellow_5gb',
                                'beneficiary' => '0244123456',
                                'method' => 'wallet',
                            ],
                        ],
                        [
                            'id' => 'csv_wallet',
                            'title' => 'CSV beneficiaries (wallet, live)',
                            'explain' => 'One UtilityOrder per phone, shared batch_id + analytics. Phones accepted as 024… or 233… (normalized).',
                            'fixture' => 'purchase_bulk_wallet',
                        ],
                        [
                            'id' => 'group',
                            'title' => 'contact_group_ids (wallet)',
                            'explain' => 'Up to group_purchase_max (default 2000). YELLO expands verified only. Jobs are staggered for Swift ~30 req/min.',
                            'request' => [
                                'service' => 'data',
                                'package_id' => 'yellow_5gb',
                                'contact_group_ids' => [9],
                                'method' => 'wallet',
                            ],
                        ],
                        [
                            'id' => 'momo',
                            'title' => 'Single airtime via MoMo (live)',
                            'explain' => 'method=momo|card → Paystack/Kairos payment_link. After paid webhook/verify, the order is queued to Swift.',
                            'fixture' => 'purchase_momo_pending',
                        ],
                        [
                            'id' => 'bulk_momo',
                            'title' => 'Mixed items[] + MoMo',
                            'explain' => 'Same checkout as single MoMo, then N orders share a PAY-{payment_id} batch once paid.',
                            'request' => [
                                'method' => 'momo',
                                'phone_number' => '0244123456',
                                'momo_network' => 'MTN',
                                'items' => [
                                    ['service' => 'airtime', 'network' => 'MTN', 'amount' => 5, 'beneficiary' => '0244123456'],
                                    ['service' => 'data', 'package_id' => 'at_3gb', 'beneficiary' => '0201234567'],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/utilities/batches/{batch}',
                    'auth' => 'client',
                    'summary' => 'Bulk analytics from Swift fulfillment. Poll until progress_percent=100. Scoped to the logged-in client only (no cost/profit).',
                    'scenarios' => [
                        [
                            'id' => 'missing',
                            'title' => 'Unknown batch (live)',
                            'fixture' => 'batch_not_found',
                        ],
                        [
                            'id' => 'ok',
                            'title' => 'Batch progress (live)',
                            'explain' => 'Statuses move pending → completed|failed via ProcessUtilityOrderJob + Swift webhooks (order.fulfilled / order.failed).',
                            'fixture' => 'batch_progress',
                        ],
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/utilities/orders',
                    'auth' => 'client',
                    'summary' => 'Filters: status, service, product_type, batch_id, from, to.',
                    'scenarios' => [['id' => 'list', 'title' => 'List utility orders', 'request' => null]],
                ],
            ],
        ],

        [
            'id' => 'client-rewards',
            'title' => 'Rewards',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/rewards',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'In-stock rewards', 'fixture' => 'client_rewards']],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/rewards/catalog',
                    'auth' => 'client',
                    'summary' => 'Swift packages for building inline campaign/survey rewards.',
                    'scenarios' => [['id' => 'catalog', 'title' => 'Reward package picker', 'fixture' => 'client_rewards_catalog']],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/reward-disbursements',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'Payout history', 'request' => null]],
                ],
            ],
        ],

        [
            'id' => 'client-campaigns',
            'title' => 'Campaigns',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/campaigns',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'List campaigns', 'fixture' => 'client_campaigns']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/campaigns',
                    'auth' => 'client',
                    'summary' => 'Creates draft only. Flow: create → fund (if rewards) → activate.',
                    'types' => [
                        ['field' => 'type', 'type' => 'occasion|promotion', 'notes' => 'immutable after create'],
                        ['field' => 'occasion_type', 'type' => 'birthday|anniversary|holiday|custom', 'notes' => 'occasion only'],
                        ['field' => 'trigger_offset', 'type' => 'on|before|after|same_day|one_day_before|…|custom', 'notes' => 'occasion'],
                        ['field' => 'contact_group_ids', 'type' => 'int[]', 'notes' => 'required'],
                        ['field' => 'reward_id / reward', 'type' => 'int | object', 'notes' => 'catalog reward or inline'],
                    ],
                    'scenarios' => [
                        [
                            'id' => 'occasion_yello',
                            'title' => 'Birthday + YELLO data reward',
                            'explain' => 'eligible_recipients = verified only when reward needs beneficiary. estimated_cost uses that count.',
                            'request' => [
                                'name' => 'Birthday blast',
                                'type' => 'occasion',
                                'occasion_type' => 'birthday',
                                'trigger_offset' => 'on',
                                'send_time' => '09:00',
                                'repeat_yearly' => true,
                                'contact_group_ids' => [9],
                                'sender' => 'CELLOGENT',
                                'body' => 'Happy birthday {{name}}! {{link}}',
                                'cta' => ['enabled' => true, 'type' => 'link', 'url' => 'https://example.com', 'triggers_reward' => true],
                                'reward_enabled' => true,
                                'reward_id' => 3,
                            ],
                        ],
                        [
                            'id' => 'promo_no_reward',
                            'title' => 'Promotion without reward',
                            'request' => [
                                'name' => 'Weekend promo',
                                'type' => 'promotion',
                                'contact_group_ids' => [9],
                                'sender' => 'CELLOGENT',
                                'body' => 'Flash sale this weekend!',
                                'send_at' => '2026-11-15 10:00:00',
                                'reward_enabled' => false,
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/campaigns/{campaign}/fund',
                    'auth' => 'client',
                    'scenarios' => [
                        ['id' => 'wallet', 'title' => 'Fund from wallet', 'request' => ['method' => 'wallet']],
                        ['id' => 'momo', 'title' => 'Fund via MoMo', 'request' => ['method' => 'momo', 'phone_number' => '0244123456', 'network' => 'MTN']],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/campaigns/{campaign}/activate',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'activate', 'title' => 'Activate', 'request' => new stdClass]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/campaigns/{campaign}/pause',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'pause', 'title' => 'Pause', 'request' => new stdClass]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/campaigns/{campaign}/resume',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'resume', 'title' => 'Resume', 'request' => new stdClass]],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/campaigns/{campaign}/analytics',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'analytics', 'title' => 'Analytics', 'response' => ['in_error' => false, 'data' => ['sent' => 0, 'delivered' => 0, 'clicks' => 0, 'rewards_paid' => 0]]]],
                ],
            ],
        ],

        [
            'id' => 'client-surveys',
            'title' => 'Surveys',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/surveys',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'List surveys', 'fixture' => 'client_surveys']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/surveys',
                    'auth' => 'client',
                    'summary' => 'Question types: text, long_text, single_choice, multiple_choice, rating, yes_no, number.',
                    'scenarios' => [
                        [
                            'id' => 'create',
                            'title' => 'Create with questions + reward',
                            'request' => [
                                'name' => 'NPS Q4',
                                'intro' => 'Help us improve',
                                'body' => 'Please take our survey: {{link}}',
                                'contact_group_ids' => [9],
                                'sender' => 'CELLOGENT',
                                'reward_enabled' => true,
                                'reward_id' => 3,
                                'questions' => [
                                    ['prompt' => 'Rate us 1–5', 'type' => 'rating', 'required' => true],
                                    ['prompt' => 'Favourite feature?', 'type' => 'single_choice', 'options' => ['SMS', 'Campaigns', 'Data'], 'required' => true],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'PUT',
                    'path' => '/client/surveys/{survey}/questions',
                    'auth' => 'client',
                    'summary' => 'Replace question list. Keep id to update existing rows.',
                    'scenarios' => [
                        [
                            'id' => 'sync',
                            'title' => 'Sync questions',
                            'request' => [
                                'questions' => [
                                    ['id' => 1, 'prompt' => 'Still happy?', 'type' => 'yes_no', 'required' => true],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/surveys/{survey}/fund',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'fund', 'title' => 'Fund rewards', 'request' => ['method' => 'wallet']]],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/surveys/{survey}/responses',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'responses', 'title' => 'List responses', 'request' => null]],
                ],
            ],
        ],

        [
            'id' => 'client-sms',
            'title' => 'SMS',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/sms/balance',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'balance', 'title' => 'Unit balance', 'fixture' => 'client_sms_balance']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/sms/quick',
                    'auth' => 'client',
                    'summary' => '`to` accepts one number, CSV, or array (normalized server-side).',
                    'scenarios' => [
                        [
                            'id' => 'array',
                            'title' => 'Quick SMS to array',
                            'request' => [
                                'from' => 'CELLOGENT',
                                'to' => ['0244123456', '0201234567'],
                                'message' => 'Hello from Cellogent',
                            ],
                        ],
                        [
                            'id' => 'single',
                            'title' => 'Quick SMS to one number',
                            'request' => [
                                'from' => 'CELLOGENT',
                                'to' => ['0244123456'],
                                'message' => 'OTP style notice',
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/sms/bulk',
                    'auth' => 'client',
                    'scenarios' => [
                        [
                            'id' => 'bulk',
                            'title' => 'Bulk to contact groups',
                            'request' => [
                                'from' => 'CELLOGENT',
                                'contact_group_ids' => [9, 12],
                                'message' => 'Promo blast…',
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/sms/{inbox}/reblast',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'reblast', 'title' => 'Reblast (optional new message)', 'request' => ['message' => 'Updated copy']]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/sms/{inbox}/resend',
                    'auth' => 'client',
                    'summary' => 'Failed recipients only; message required.',
                    'scenarios' => [['id' => 'resend', 'title' => 'Resend failures', 'request' => ['message' => 'Retry copy']]],
                ],
            ],
        ],

        [
            'id' => 'client-contacts',
            'title' => 'Contacts & groups',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/contact-groups',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'List groups (live)', 'fixture' => 'client_contact_groups']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/contact-groups',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'create', 'title' => 'Create group', 'request' => ['name' => 'VIP', 'description' => 'High value']]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/contact-groups/{contactGroup}/contacts',
                    'auth' => 'client',
                    'scenarios' => [[
                        'id' => 'add',
                        'title' => 'Add contact',
                        'request' => ['name' => 'Kojo', 'phone_number' => '0244123456', 'birthday' => '1990-05-01'],
                    ]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/contact-groups/{contactGroup}/contacts/upload',
                    'auth' => 'client',
                    'summary' => 'multipart CSV/XLSX. Auto-queues beneficiary verify for MTN numbers.',
                    'scenarios' => [[
                        'id' => 'upload',
                        'title' => 'Upload contacts file',
                        'request' => ['file' => '(csv/xlsx)'],
                    ]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/contact-groups/{contactGroup}/verify-beneficiaries',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'verify', 'title' => 'Queue verify', 'request' => new stdClass]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/contact-groups/{contactGroup}/submit-beneficiaries',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'submit', 'title' => 'Queue submit to MTN', 'request' => new stdClass]],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/sender-ids',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'Sender IDs', 'fixture' => 'client_sender_ids']],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/sender-ids',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'create', 'title' => 'Request sender ID', 'request' => ['name' => 'CELLOGENT', 'description' => 'Transactional']]],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/api-keys',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'list', 'title' => 'List API keys', 'request' => null]],
                ],
                [
                    'method' => 'POST',
                    'path' => '/client/api-keys',
                    'auth' => 'client',
                    'scenarios' => [['id' => 'create', 'title' => 'Create API key', 'request' => ['name' => 'Production']]],
                ],
            ],
        ],

        [
            'id' => 'client-reports',
            'title' => 'Reports & exports',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/reports/{report}',
                    'auth' => 'client',
                    'summary' => 'report ∈ sms|delivery|payments|wallet|subscriptions. Query: period, from, to.',
                    'scenarios' => [['id' => 'sms', 'title' => 'SMS report', 'request' => null]],
                ],
                [
                    'method' => 'GET',
                    'path' => '/client/exports/{dataset}',
                    'auth' => 'client',
                    'summary' => 'dataset ∈ payments|wallet|subscriptions|sms|sms-delivery. Returns file download.',
                    'scenarios' => [['id' => 'export', 'title' => 'Download dataset', 'request' => null]],
                ],
            ],
        ],
    ],
];
