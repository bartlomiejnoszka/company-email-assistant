<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

interface RunLock
{
    public function acquire(): bool;
    public function release(): void;
}
