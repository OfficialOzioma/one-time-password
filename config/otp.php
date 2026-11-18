<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Security keys
    |--------------------------------------------------------------------------
    |
    | Generate two independent secrets of at least 32 bytes. Keep them the
    | same across every application instance. Changing either key invalidates
    | outstanding OTP records.
    |
    */
    'hash_keys' => [
        'token' => env('OTP_HASH_KEY'),
        'identifier' => env('OTP_IDENTIFIER_HASH_KEY'),
    ],

    /* Global defaults. */
    'defaults' => [
        'validity' => 10,
        'length' => 6,
        'type' => 'numeric',
        'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'max_attempts' => 5,
        'resend_cooldown' => 60,
        'max_generations' => 5,
        'generation_window' => 60,
    ],

    /* Defaults that override the global values for a named purpose. */
    'purposes' => [
        'email_verification' => [
            'validity' => 10,
            'length' => 6,
            'type' => 'numeric',
            'max_attempts' => 5,
        ],
        'phone_verification' => [
            'validity' => 5,
            'length' => 6,
            'type' => 'numeric',
            'max_attempts' => 5,
        ],
        'password_reset' => [
            'validity' => 5,
            'length' => 6,
            'type' => 'numeric',
            'max_attempts' => 5,
        ],
        'login' => [
            'validity' => 5,
            'length' => 6,
            'type' => 'numeric',
            'max_attempts' => 5,
        ],
        'transaction_authorization' => [
            'validity' => 3,
            'length' => 6,
            'type' => 'numeric',
            'max_attempts' => 3,
        ],
    ],

    'verification_rate_limit' => [
        'enabled' => true,
        'max_attempts' => 20,
        'decay_seconds' => 3600,
        'purposes' => [
            'transaction_authorization' => [
                'max_attempts' => 10,
                'decay_seconds' => 3600,
            ],
        ],
    ],

    /* Laravel adapter settings. */
    'cache_store' => env('OTP_CACHE_STORE'),
    'connection' => env('OTP_DB_CONNECTION'),
    'table' => env('OTP_TABLE', 'otps'),
    'rate_limit_table' => env('OTP_RATE_LIMIT_TABLE', 'otp_rate_limits'),

    'locks' => [
        'generation_lock_wait_seconds' => 5,
        'lease_seconds' => 60,
    ],
];
