<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pilot availability
    |--------------------------------------------------------------------------
    |
    | The pilot is deliberately disabled by default. An explicit end timestamp
    | is required even when enablement is turned on so a misconfigured
    | environment fails closed.
    |
    */

    'enabled' => (bool) env('CAPSTONE_PILOT_ENABLED', false),

    'expires_at' => env('CAPSTONE_PILOT_EXPIRES_AT'),

    /*
    |--------------------------------------------------------------------------
    | Participant capacity
    |--------------------------------------------------------------------------
    |
    | This is a provisioning guard, not a substitute for database uniqueness.
    |
    */

    'participant_limit' => (int) env('CAPSTONE_PILOT_PARTICIPANT_LIMIT', 75),

    /*
    |--------------------------------------------------------------------------
    | Private credential export
    |--------------------------------------------------------------------------
    |
    | The disk must remain private. A public disk is rejected by the
    | provisioning command before it writes a manifest.
    |
    */

    'credential_disk' => env('CAPSTONE_PILOT_CREDENTIAL_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Participant login limits
    |--------------------------------------------------------------------------
    |
    | The endpoint applies both limits independently. The participant-code
    | key is hashed before it reaches the rate limiter or any cache backend.
    |
    */

    'rate_limits' => [
        'ip_per_minute' => (int) env('CAPSTONE_PILOT_LOGIN_IP_RATE_LIMIT', 10),
        'code_per_minute' => (int) env('CAPSTONE_PILOT_LOGIN_CODE_RATE_LIMIT', 5),
    ],

];
