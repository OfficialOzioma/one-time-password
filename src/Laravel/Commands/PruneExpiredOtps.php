<?php

declare(strict_types=1);

namespace Ozioma\Otp\Laravel\Commands;

use Illuminate\Console\Command;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;

final class PruneExpiredOtps extends Command
{
    protected $signature = 'otp:prune-expired {--chunk=1000 : Maximum records to delete per run}';
    protected $description = 'Delete expired Ozioma OTP records';

    public function handle(OtpRepositoryInterface $repository, ClockInterface $clock): int
    {
        $limit = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100000) {
            $this->error('The chunk option must be an integer between 1 and 100000.');
            return self::FAILURE;
        }

        $deleted = $repository->pruneExpired($clock->now(), $limit);
        $this->info("Deleted {$deleted} expired OTP record(s).");

        return self::SUCCESS;
    }
}
