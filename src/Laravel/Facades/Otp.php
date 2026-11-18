<?php

declare(strict_types=1);

namespace Ozioma\Otp\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\PendingOtp;

/** @method static PendingOtp for(string $identifier, string $purpose) */
final class Otp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OtpManager::class;
    }
}
