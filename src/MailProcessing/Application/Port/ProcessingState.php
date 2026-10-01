<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

use App\MailProcessing\Domain\ProcessingRecord;

interface ProcessingState
{
    public function assertReady(): void;
    public function lockPath(): string;
    public function lockKey(): string;
    public function checkValidity(string $mailbox, string $validity, bool $dryRun): void;
    public function find(string $mailbox, string $validity, int $uid): ?ProcessingRecord;
    public function recoverable(string $mailbox): array;
    public function save(ProcessingRecord $record): void;
}
