<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\Filesystem;

use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Exceptions\LockTimeoutException;
use RuntimeException;

final class FileLockManager implements LockManagerInterface
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        if (trim($directory) === '' || is_link($directory)) {
            throw new RuntimeException('OTP lock directory must be a real, non-empty directory path.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create OTP lock directory.');
        }

        $resolved = realpath($directory);
        if ($resolved === false || is_link($resolved)) {
            throw new RuntimeException('Could not resolve a safe OTP lock directory.');
        }

        $trimmed = rtrim($resolved, '/\\');
        $isFilesystemRoot = $trimmed === ''
            || preg_match('/\A[A-Za-z]:\z/', $trimmed) === 1;

        if ($isFilesystemRoot) {
            throw new RuntimeException('The filesystem root cannot be used as the OTP lock directory.');
        }

        if (!@chmod($resolved, 0700)) {
            $permissions = fileperms($resolved);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw new RuntimeException('OTP lock directory permissions must restrict access to its owner.');
            }
        }

        $this->directory = $resolved;
    }

    public function synchronized(string $key, int $waitSeconds, callable $callback): mixed
    {
        if ($waitSeconds < 1 || $waitSeconds > 300) {
            throw new \InvalidArgumentException('Lock wait time must be between 1 and 300 seconds.');
        }

        $path = $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.lock';

        // The directory is private to the application user. This check also
        // detects a pre-existing malicious link before opening the file.
        if (is_link($path)) {
            throw new RuntimeException('OTP lock path must not be a symbolic link.');
        }

        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Could not open OTP lock file.');
        }

        if (!@chmod($path, 0600)) {
            $permissions = fileperms($path);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                fclose($handle);
                throw new RuntimeException('OTP lock file permissions must restrict access to its owner.');
            }
        }

        $deadline = hrtime(true) + ($waitSeconds * 1_000_000_000);

        try {
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new LockTimeoutException('Timed out waiting for an OTP generation lock.');
                }

                usleep(20_000);
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
