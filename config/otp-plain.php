<?php

declare(strict_types=1);

$environment = static function (string $key): ?string {
    $value = getenv($key);

    return $value === false ? null : (string) $value;
};

return [
    'hash_keys' => [
        'token' => $environment('OTP_HASH_KEY'),
        'identifier' => $environment('OTP_IDENTIFIER_HASH_KEY'),
    ],

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

    'cache_store' => null,
    'connection' => null,
    'table' => 'otps',
    'rate_limit_table' => 'otp_rate_limits',

    'locks' => [
        'generation_lock_wait_seconds' => 5,
        'lease_seconds' => 60,
    ],
];
