<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

final class MailboxState
{
    public function __construct(
        public string $id,
        public string $uidValidity,
    ) {
    }
}
