<?php

use Ozioma\Otp\Laravel\Facades\Otp;
use Ozioma\Otp\Laravel\Rules\OtpRule;

// Generate and send using your own Laravel Notification/Mailable/SMS provider.
$generated = Otp::for($user->email, 'email_verification')
    ->validFor(10)
    ->length(6)
    ->numeric()
    ->generate();

// In a FormRequest or controller validation array:
$rules = [
    'otp' => [
        'required',
        'string',
        'digits:6',
        new OtpRule(
            identifier: $user->email,
            purpose: 'email_verification',
            message: 'The code is invalid or expired.',
        ),
    ],
];
