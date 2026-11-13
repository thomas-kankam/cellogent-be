<?php

return [
    'id' => 'public',
    'title' => 'Public',
    'blurb' => 'No login. Plans for signup UI, plus survey/campaign pages recipients open from SMS links.',
    'groups' => [
        [
            'id' => 'public-plans',
            'title' => 'Plans catalogue',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/client/plans',
                    'auth' => 'none',
                    'summary' => 'Active SMS unit plans + packages (months/units/price). months=0 means non-expiry.',
                    'types' => [
                        ['field' => 'details[].months', 'type' => 'integer', 'notes' => '0 = non-expiry; 1–36 = dated pack'],
                        ['field' => 'details[].units', 'type' => 'integer', 'notes' => 'SMS units granted'],
                        ['field' => 'details[].price', 'type' => 'number', 'notes' => 'GHS price before payment charges'],
                    ],
                    'scenarios' => [
                        [
                            'id' => 'list',
                            'title' => 'List plans',
                            'explain' => 'Use this to render the pricing cards before subscribe.',
                            'fixture' => 'plans',
                        ],
                    ],
                ],
            ],
        ],
        [
            'id' => 'public-engagement',
            'title' => 'Survey & campaign links',
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/public/surveys/{token}',
                    'auth' => 'none',
                    'summary' => 'Respondent view. token = survey.public_token from the client survey object.',
                    'scenarios' => [
                        [
                            'id' => 'show',
                            'title' => 'Load survey',
                            'explain' => 'Only public fields — no funding or group ids.',
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'Survey loaded.',
                                'reason' => 'success',
                                'data' => [
                                    'name' => 'NPS Q4',
                                    'intro' => 'Help us improve',
                                    'status' => 'active',
                                    'accepting_responses' => true,
                                    'questions' => [
                                        ['id' => 1, 'prompt' => 'Rate us', 'type' => 'rating', 'options' => null, 'required' => true, 'position' => 0],
                                    ],
                                    'reward' => ['name' => 'YELLO 1GB Data', 'type' => 'data', 'size' => '1GB', 'amount' => 4.2, 'network' => 'YELLO', 'currency' => 'GHS'],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/public/surveys/{token}/click',
                    'auth' => 'none',
                    'summary' => 'Track link open. phone_number optional.',
                    'scenarios' => [
                        [
                            'id' => 'click',
                            'title' => 'Track click',
                            'request' => ['phone_number' => '0244123456'],
                            'response' => ['in_error' => false, 'status_code' => '200', 'message' => 'Click recorded.', 'reason' => 'success', 'data' => ['tracked' => true]],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/public/surveys/{token}/submit',
                    'auth' => 'none',
                    'summary' => 'Submit answers. answers keys are question ids. YELLO data rewards skip unverified phones.',
                    'scenarios' => [
                        [
                            'id' => 'submit',
                            'title' => 'Submit answers',
                            'request' => [
                                'phone_number' => '0244123456',
                                'answers' => ['1' => '5', '2' => 'Great service'],
                            ],
                            'response' => [
                                'in_error' => false,
                                'status_code' => '200',
                                'message' => 'Response saved.',
                                'reason' => 'success',
                                'data' => ['submitted' => true, 'reward_status' => 'queued'],
                            ],
                        ],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/public/campaigns/{campaign}/click',
                    'auth' => 'none',
                    'summary' => 'Campaign CTA tracking URL (also on campaign.tracking_url).',
                    'scenarios' => [
                        [
                            'id' => 'click',
                            'title' => 'Track campaign click',
                            'request' => ['phone_number' => '0244123456'],
                            'response' => ['in_error' => false, 'status_code' => '200', 'message' => 'Click recorded.', 'reason' => 'success', 'data' => ['tracked' => true]],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
