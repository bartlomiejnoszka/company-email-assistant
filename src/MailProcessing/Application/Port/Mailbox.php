<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

use App\MailProcessing\Domain\SourceMessage;

interface Mailbox
{
    public function connect(): void;
    public function identity(): string;
    public function uidValidity(): string;
    /** @return iterable<SourceMessage> Metadata only; no bodies or Seen mutations. */
    public function sources(\DateTimeImmutable $since): iterable;
    public function body(SourceMessage $source): string;
    public function append(string $mime): ?string;
    /** @return list<string> Draft provider UIDs matching the exact RFC Message-ID. */
    public function findDraft(string $messageId): array;
    public function flag(int $uid): void;
    public function disconnect(): void;
}
