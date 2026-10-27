<?php

return [

    /*
    | How long an unused OTP stays valid.
    */
    'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 10),

    /*
    | Max request/resend attempts per email + guard inside the decay window.
    */
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
    | Decay window for the attempt counter, in seconds (default 10 minutes).
    */
    'decay_seconds' => (int) env('OTP_DECAY_SECONDS', 600),

    /*
    | Only when APP_ENV=local: include the raw OTP in the JSON so Postman
    | testing works before mail/Horizon is running. Never enable this live.
    */
    'expose_in_local' => (bool) env('OTP_EXPOSE_IN_LOCAL', true),
];
