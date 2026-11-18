<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use DateTimeImmutable;
use DateTimeZone;
use Ozioma\Otp\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
