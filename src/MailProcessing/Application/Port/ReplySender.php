<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

use App\MailProcessing\Domain\EmailAddress as Address;

interface ReplySender
{
    public function preflight(): void;
    public function send(string $mime, Address $from, Address $to): void;
}
