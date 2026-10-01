<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Domain\MailboxState;
use App\MailProcessing\Domain\ProcessingRecord;
use Doctrine\ORM\EntityManagerInterface;

final class StateStore implements \App\MailProcessing\Application\Port\ProcessingState
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }
    public function assertReady(): void
    {
        $params = $this->em->getConnection()->getParams();
        if (isset($params['path']) && !is_file($params['path'])) {
            throw new \RuntimeException('state_database_missing_run_migrations');
        }
        $this->em->getConnection()->executeQuery('SELECT id FROM processing_record LIMIT 0');
        $this->em->getConnection()->executeQuery('SELECT id FROM mailbox_state LIMIT 0');
    }
    public function lockPath(): string
    {
        $path = $this->em->getConnection()->getParams()['path'] ?? sys_get_temp_dir().'/office-drafts-memory.sqlite';
        return dirname($path);
    }
    public function lockKey(): string
    {
        return 'office-drafts-'.hash('sha256', $this->em->getConnection()->getParams()['path'] ?? 'memory');
    }
    public function checkValidity(string $mailbox, string $validity, bool $dryRun): void
    {
        $state = $this->em->find(MailboxState::class, $mailbox);
        if ($state && $state->uidValidity !== $validity) {
            throw new \App\MailProcessing\Domain\MailboxEpochChanged('uidvalidity_changed_operator_reconciliation_required');
        }
        if (!$state && !$dryRun) {
            $this->em->persist(new MailboxState($mailbox, $validity));
            $this->em->flush();
        }
    }
    public function find(string $mailbox, string $validity, int $uid): ?ProcessingRecord
    {
        return $this->em->find(ProcessingRecord::class, ProcessingRecord::key($mailbox, $validity, $uid));
    }
    /** Recovery is independent of whether the source is still in the Inbox or within the start window. */
    public function recoverable(string $mailbox): array
    {
        return $this->em->createQuery('SELECT r FROM App\MailProcessing\Domain\ProcessingRecord r WHERE r.mailbox = :mailbox AND (r.status IN (:pending) OR r.flagPending = true)')
            ->setParameter('mailbox', $mailbox)->setParameter('pending', ['append_pending', 'send_pending'])->getResult();
    }
    public function save(ProcessingRecord $record): void
    {
        $record->updatedAt = new \DateTimeImmutable();
        $this->em->persist($record);
        $this->em->flush();
    }
}
