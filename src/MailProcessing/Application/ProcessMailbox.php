<?php

declare(strict_types=1);

namespace App\MailProcessing\Application;

use App\Reply\Application\Port\DraftAi;
use App\MailProcessing\Domain\ProcessingRecord;
use App\MailProcessing\Application\Port\Mailbox;
use App\MailProcessing\Domain\ManualHandling;
use App\MailProcessing\Application\Port\MimeComposer;
use App\MailProcessing\Application\Port\ContentExtractor;
use App\MailProcessing\Application\Port\ReplySender;
use App\MailProcessing\Domain\TestAutoReply;
use App\Configuration\Application\Port\ConfigProvider;
use App\Reply\Application\ResponseGenerator;
use App\MailProcessing\Application\Port\ProcessingState;
use App\MailProcessing\Application\Port\RunLock;

final class ProcessMailbox
{
    public function __construct(
        private readonly ConfigProvider $loader,
        private readonly Mailbox $mailbox,
        private readonly DraftAi $ai,
        private readonly ProcessingState $state,
        private readonly ContentExtractor $extractor,
        private readonly ResponseGenerator $generator,
        private readonly MimeComposer $composer,
        private readonly string $startAt,
        private readonly RunLock $runLock,
        private readonly ?ReplySender $sender = null,
        private readonly TestAutoReply $autoReply = new TestAutoReply(),
    ) {
    }
    public function run(bool $dryRun, mixed $requestedLimit, \Closure $report): int
    {
        // This entire gate precedes all mailbox reads and writes, including recovery.
        try {
            $config = $this->loader->load();
        } catch (\InvalidArgumentException $e) {
            $report($e->getMessage());
            return 2;
        }
        $limit = filter_var($requestedLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $this->startAt)) {
            $report('Set EMAIL_START_AT to an ISO-8601 timestamp with timezone; --limit must be 1..1000.');
            return 2;
        }
        try {
            $since = new \DateTimeImmutable($this->startAt);
            if (\DateTimeImmutable::getLastErrors() !== false) {
                throw new \InvalidArgumentException();
            }
            $this->ai->preflight();
            if ($this->autoReply->enabled()) {
                if (!$this->sender) {
                    throw new \LogicException('Missing SMTP sender');
                }
                $this->sender->preflight();
            }
            $this->state->assertReady();
        } catch (\Throwable $e) {
            $report('Preflight failed: check EMAIL_START_AT, AI/SMTP settings and migrated SQLite database. Type: '.$e::class);
            return 1;
        }
        $dry = $dryRun;
        $lock = $this->runLock;
        if (!$lock->acquire()) {
            $report('Another draft generation run holds the lock; no work performed.');
            return 0;
        }
        $counts = ['sent' => 0, 'drafted' => 0, 'manual' => 0, 'skipped' => 0, 'failed' => 0, 'reconciled' => 0];
        $errors = false;
        try {
            $this->mailbox->connect();
            $identity = $this->mailbox->identity();
            $validity = $this->mailbox->uidValidity();
            $this->state->checkValidity($identity, $validity, $dry);
            foreach ($this->state->recoverable($identity) as $stored) {
                $record = $dry ? clone $stored : $stored;
                if ($record->status === 'send_pending') {
                    $record->status = 'send_unknown';
                    $record->reason = 'send_outcome_uncertain';
                    $record->flagPending = true;
                    $errors = true;
                    ++$counts['failed'];
                    if (!$dry) {
                        $this->state->save($record);
                    }
                }
                if ($record->status === 'append_pending') {
                    try {
                        $matches = $this->mailbox->findDraft($record->draftMessageId);
                        if (count($matches) === 1) {
                            $record->status = 'drafted';
                            $record->draftUid = $matches[0];
                            $record->error = null;
                            ++$counts['reconciled'];
                        } else {
                            $record->status = 'append_unknown';
                            $record->reason = 'append_outcome_uncertain';
                            $record->flagPending = true;
                            $errors = true;
                            ++$counts['failed'];
                        }
                        if (!$dry) {
                            $this->state->save($record);
                        }
                    } catch (\Throwable) {
                        // Keep append_pending so the next run can search again, but never append again.
                        $errors = true;
                        ++$counts['failed'];
                        $report('UID '.$record->sourceUid.': draft reconciliation unavailable; APPEND prohibited.');
                        continue;
                    }
                }
                $errors = !$this->flag($record, $dry, $report) || $errors;
                $report(($dry ? 'DRY ' : '').'UID '.$record->sourceUid.': '.$record->status);
            }
            $handled = 0;
            foreach ($this->mailbox->sources($since) as $source) {
                $stored = $this->state->find($identity, $validity, $source->uid);
                if ($stored && ($stored->terminal() || in_array($stored->status, ['append_pending', 'send_pending'], true))) {
                    ++$counts['skipped'];
                    continue;
                }
                if ($handled++ >= $limit) {
                    break;
                }
                $record = $stored ? ($dry ? clone $stored : $stored) : new ProcessingRecord($identity, $validity, $source->uid, $config->hash);
                try {
                    if ($record->attempts >= 3) {
                        throw new ManualHandling('retry_limit_reached');
                    }
                    ++$record->attempts;
                    $record->status = 'processing';
                    $record->configHash = $config->hash;
                    $record->error = null;
                    $record->reason = null;
                    $record->selectedIds = [];
                    if (!$dry) {
                        $this->state->save($record);
                    }
                    if ($this->composer->skip($source, $config)) {
                        $record->status = 'skipped';
                        $record->reason = 'automated_or_self';
                        if (!$dry) {
                            $this->state->save($record);
                        }
                        ++$counts['skipped'];
                        continue;
                    }
                    $record->sourceMessageId = $this->composer->sourceMessageId($source);
                    $recipient = $this->composer->recipient($source);
                    $autoSend = $this->autoReply->matches($source);
                    if ($autoSend) {
                        $this->autoReply->assertRecipient($recipient->getAddress(), $config);
                    }
                    $extracted = $this->extractor->extract($this->mailbox->body($source));
                    $record->flagPending = $record->flagPending || $extracted->attachments;
                    if ($extracted->attachments) {
                        $record->reason = 'attachments_require_review';
                    }
                    $result = $this->generator->generate($config, $source->subject(), $extracted->text);
                    $record->selectedIds = $result->selectedBlockIds;
                    $record->reason ??= $result->reason.($result->rewritten ? '_mixed' : '');
                    $body = $result->response;
                    $record->draftMessageId ??= 'office-draft-'.$record->id.'@'.explode('@', $config->company['sender_address'])[1];
                    $mime = $this->composer->compose($source, $config, $body, $record->draftMessageId, $autoSend);
                    if ($dry) {
                        $report('DRY UID '.$source->uid.': '.($autoSend ? 'would send: ' : 'would draft: ').$result->outcome.($result->rewritten ? ' (redakcja mixed)' : '').'; '.implode(', ', $record->selectedIds)."\n".$body."\n");
                    } elseif ($autoSend) {
                        $record->status = 'send_pending';
                        $this->state->save($record); // Never retry once SMTP may have accepted the message.
                        $this->sender->send($mime, new \App\MailProcessing\Domain\EmailAddress($config->company['sender_address']), $recipient);
                        $record->status = 'sent';
                        $this->state->save($record);
                    } else {
                        $record->status = 'append_pending';
                        $this->state->save($record); // Durably commit before the external side effect.
                        $record->draftUid = $this->mailbox->append($mime);
                        $record->status = 'drafted';
                        $this->state->save($record);
                    }
                    ++$counts[$autoSend ? 'sent' : 'drafted'];
                } catch (\App\MailProcessing\Domain\MailboxEpochChanged $e) {
                    throw $e;
                } catch (ManualHandling $e) {
                    $record->status = 'manual';
                    $record->reason = $e->getMessage();
                    $record->flagPending = true;
                    if (!$dry) {
                        $this->state->save($record);
                    }
                    ++$counts['manual'];
                    if (in_array($record->reason, ['invalid_ai_output', 'invalid_ai_output_or_refusal', 'invalid_ai_rewrite', 'ai_rewrite_requires_staff', 'invalid_ai_topic_match', 'retry_limit_reached'], true)) {
                        $errors = true;
                    }
                    $report(($dry ? 'DRY ' : '').'UID '.$source->uid.': manual ('.$record->reason.')');
                } catch (\Throwable $e) {
                    $errors = true;
                    ++$counts['failed'];
                    // After APPEND begins (or the final commit fails), never permit an automatic new append.
                    if (in_array($record->status, ['send_pending', 'sent'], true)) {
                        $record->status = 'send_unknown';
                        $record->flagPending = true;
                        $record->reason = 'send_outcome_uncertain';
                    } elseif (in_array($record->status, ['append_pending', 'drafted'], true)) {
                        $record->status = 'append_pending';
                        $record->flagPending = true;
                        $record->reason = 'append_reconciliation_required';
                    } else {
                        $record->status = $record->attempts >= 3 ? 'manual' : 'failed';
                        $record->flagPending = $record->flagPending || $record->attempts >= 3;
                        $record->reason = $record->attempts >= 3 ? 'retry_limit_reached' : 'pre_append_failure';
                    }
                    $record->error = 'operation_failed'; // Never save exception messages: providers may echo email/key material.
                    if (!$dry) {
                        $this->state->save($record);
                    }
                    $report(($dry ? 'DRY ' : '').'UID '.$source->uid.': '.$record->reason.' ('.$e::class.')');
                }
                $errors = !$this->flag($record, $dry, $report) || $errors;
            }
        } catch (\App\MailProcessing\Domain\MailboxEpochChanged) {
            $errors = true;
            $report('Inbox UIDVALIDITY changed. Stop scheduling and reconcile mailbox identities before resuming.');
        } catch (\Throwable $e) {
            $errors = true;
            $report('Run stopped: check IMAP connectivity, folder names, SQLite state and UIDVALIDITY. Type: '.$e::class);
        } finally {
            try {
                $this->mailbox->disconnect();
            } catch (\Throwable) {
                $errors = true;
            }
            $lock->release();
        }
        $report(($dry ? 'DRY RUN ' : '').json_encode($counts, JSON_THROW_ON_ERROR));
        return $errors ? 1 : 0;
    }
    private function flag(ProcessingRecord $record, bool $dry, \Closure $report): bool
    {
        if (!$record->flagPending) {
            return true;
        }
        if ($dry) {
            $report('DRY UID '.$record->sourceUid.': would set Flagged.');
            return true;
        }
        try {
            $this->mailbox->flag($record->sourceUid);
            $record->flagPending = false;
            $this->state->save($record);
            return true;
        } catch (\App\MailProcessing\Domain\MailboxEpochChanged $e) {
            throw $e;
        } catch (\Throwable) {
            $report('UID '.$record->sourceUid.': flag failed; will retry without regenerating draft.');
            return false;
        }
    }
}
