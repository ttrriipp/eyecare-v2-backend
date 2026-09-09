<?php

$commaSeparated = static fn (string $value): array => array_values(array_filter(
    array_map('trim', explode(',', $value)),
    static fn (string $item): bool => $item !== '',
));

return [
    /*
    |--------------------------------------------------------------------------
    | Release and operator checks
    |--------------------------------------------------------------------------
    |
    | These values identify an immutable deployment and protect the internal
    | readiness endpoint. Secrets are read from the environment only.
    |
    */

    'release_id' => env('RELEASE_ID'),
    'readiness_token' => env('PILOT_READINESS_TOKEN'),
    'uptime_alert_url' => env('UPTIME_ALERT_URL'),
    // `demo` is staff-only, `pilot` is participant-code authentication, and
    // `patient-demo` is an explicit temporary phone-authentication profile.
    'mode' => env('DEPLOYMENT_MODE', 'pilot'),
    'phone_auth_enabled' => filter_var(env('PHONE_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Network boundary configuration
    |--------------------------------------------------------------------------
    |
    | Values are comma-separated in deployment environments so the same
    | provider-neutral configuration works with a managed host or a volume.
    |
    */

    'trusted_hosts' => $commaSeparated((string) env('TRUSTED_HOSTS', '')),
    'trusted_proxies' => $commaSeparated((string) env('TRUSTED_PROXIES', '')),
    'allowed_origins' => $commaSeparated((string) env('CORS_ALLOWED_ORIGINS', '')),
];
