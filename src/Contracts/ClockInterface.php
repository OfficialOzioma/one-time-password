<?php

declare(strict_types=1);

namespace Ozioma\Otp\Contracts;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
