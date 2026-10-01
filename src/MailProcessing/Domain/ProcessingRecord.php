<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

final class ProcessingRecord
{
    public string $id;
    public string $mailbox;
    public string $uidValidity;
    public int $sourceUid;
    public ?string $sourceMessageId = null;
    public string $status = 'processing';
    public int $attempts = 0;
    public ?string $draftMessageId = null;
    public ?string $draftUid = null;
    public string $configHash;
    public array $selectedIds = [];
    public ?string $reason = null;
    public ?string $error = null;
    public bool $flagPending = false;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
    public function __construct(string $mailbox, string $validity, int $uid, string $hash)
    {
        $this->id = self::key($mailbox, $validity, $uid);
        $this->mailbox = $mailbox;
        $this->uidValidity = $validity;
        $this->sourceUid = $uid;
        $this->configHash = $hash;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
    public static function key(string $mailbox, string $validity, int $uid): string
    {
        return hash('sha256', $mailbox.'|'.$validity.'|'.$uid);
    }
    public function terminal(): bool
    {
        return in_array($this->status, ['drafted', 'manual', 'skipped', 'append_unknown', 'sent', 'send_unknown'], true);
    }
}
