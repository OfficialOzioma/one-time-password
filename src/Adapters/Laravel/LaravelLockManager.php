<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException as LaravelLockTimeoutException;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Exceptions\LockTimeoutException;

final class LaravelLockManager implements LockManagerInterface
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ?string $store = null,
        private readonly int $leaseSeconds = 60,
    ) {
        if ($this->leaseSeconds < 1 || $this->leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Lock lease must be between 1 and 3600 seconds.');
        }
    }

    public function synchronized(string $key, int $waitSeconds, callable $callback): mixed
    {
        if ($waitSeconds < 1 || $waitSeconds > 300) {
            throw new \InvalidArgumentException('Lock wait time must be between 1 and 300 seconds.');
        }

        $lock = $this->cache->store($this->store)->lock(
            'ozioma-otp:' . hash('sha256', $key),
            $this->leaseSeconds,
        );

        try {
            return $lock->block($waitSeconds, $callback);
        } catch (LaravelLockTimeoutException $exception) {
            throw new LockTimeoutException('Timed out waiting for an OTP lock.', 0, $exception);
        }
    }
}
