<?php

declare(strict_types=1);

namespace App\Reply\Infrastructure;

use App\Reply\Application\Port\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
