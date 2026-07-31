<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact Lookup Key
    |--------------------------------------------------------------------------
    |
    | The key used for HMAC blind-index computation on contact values.
    | This key must be different from the application encryption key.
    | Rotate this key requires a controlled reindex operation.
    |
    */

    'contact_lookup_key' => env('CONTACT_LOOKUP_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Phone Number Default Country
    |--------------------------------------------------------------------------
    |
    | The default country code used for normalizing phone numbers.
    | Philippine numbers are normalized to E.164 format (+63...).
    |
    */

    'phone_default_country' => 'PH',

    /*
    |--------------------------------------------------------------------------
    | OTP Configuration
    |--------------------------------------------------------------------------
    |
    | Default values for OTP challenge lifecycle.
    |
    */

    'otp' => [
        'length' => 6,
        'lifetime_minutes' => 10,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'issue_limit_per_destination_per_window' => 3,
        'issue_limit_per_ip_per_window' => 10,
        'window_minutes' => 15,
        'daily_limit_per_destination' => 10,
        'verification_limit_per_destination_per_window' => 10,
        'verification_limit_per_ip_per_window' => 20,
        'prune_after_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Patient Token Configuration
    |--------------------------------------------------------------------------
    |
    | Default values for patient Sanctum token lifecycle.
    |
    */

    'tokens' => [
        'expiry_days' => 30,
        'max_active' => 5,
        'prune_grace_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitation Configuration
    |--------------------------------------------------------------------------
    |
    | Default values for patient invitation lifecycle.
    |
    */

    'invitations' => [
        'lifetime_days' => 7,
        'resend_cooldown_minutes' => 5,
        'retention_years' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment Request Configuration
    |--------------------------------------------------------------------------
    |
    | Default values for appointment request lifecycle.
    |
    */

    'appointment_requests' => [
        'hold_duration_minutes' => 30,
        'expiry_hours' => 24,
        'max_active_per_account' => 2,
        'retention_years' => 2,
    ],

];
