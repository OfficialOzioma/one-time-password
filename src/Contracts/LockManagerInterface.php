<?php

declare(strict_types=1);

namespace Ozioma\Otp\Contracts;

interface LockManagerInterface
{
    /**
     * Acquire a lock within the given wait time and hold it until the callback returns.
     *
     * Implementations must throw LockTimeoutException when the lock cannot be acquired.
     */
    public function synchronized(string $key, int $waitSeconds, callable $callback): mixed;
}
