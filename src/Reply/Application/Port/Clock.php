<?php

declare(strict_types=1);

namespace App\Reply\Application\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
