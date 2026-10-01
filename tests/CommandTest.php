<?php

declare(strict_types=1);

namespace App\Tests;

use App\MailProcessing\Domain\MailboxState;
use App\MailProcessing\Domain\ProcessingRecord;
use App\MailProcessing\Infrastructure\StateStore;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\{LockFactory, Store\FlockStore};

require_once __DIR__.'/Support.php';
final class CommandTest extends TestCase
{
    private string $dir;
    private EntityManager $em;
    private FakeMailbox $mail;
    private FakeAi $ai;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/office-command-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->em = Support::em($this->dir.'/state.sqlite');
        $this->mail = new FakeMailbox();
        $this->mail->messages = [1 => Support::mime()];
        $this->ai = new FakeAi();
        Support::writeConfig($this->dir);
    }
    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        foreach (glob($this->dir.'/*') as $file) {
            unlink($file);
        } rmdir($this->dir);
    }
    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester(Support::command(new FixtureConfigProvider($this->dir), $this->mail, $this->ai, $this->em));
        $tester->execute($options);
        return $tester;
    }
    private function record(): ProcessingRecord
    {
        return (new StateStore($this->em))->find($this->mail->identity(), '1', 1);
    }
    public function testInvalidConfigurationStopsBeforeMailbox(): void
    {
        $c = Support::config();
        $c['office']['name'] = '__REQUIRED__';
        Support::writeConfig($this->dir, $c);
        self::assertSame(2, $this->runCommand()->getStatusCode());
        self::assertSame(0, $this->mail->connections);
        self::assertSame(0, $this->ai->calls);
    }
    public function testRepeatAndChangedConfigurationDoNotDuplicateOrFetchBody(): void
    {
        self::assertSame(0, $this->runCommand()->getStatusCode());
        $c = Support::config();
        $c['office']['signature'] = 'Changed';
        Support::writeConfig($this->dir, $c);
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertSame(1, $this->mail->appends);
        self::assertSame(1, $this->mail->bodyCalls);
        self::assertSame('drafted', $this->record()->status);
        // Staff deleting/sending a draft must not cause it to be regenerated.
        $this->mail->drafts = [];
        $this->runCommand();
        self::assertSame(1, $this->mail->appends);
    }
    public function testCrashAfterAppendIsReconciledWithoutDuplicate(): void
    {
        $this->mail->crashAfterAppend = true;
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertSame('append_pending', $this->record()->status);
        $this->mail->crashAfterAppend = false;
        $this->em->clear(); // Reload committed state, as a fresh cron process would.
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertSame('drafted', $this->record()->status);
        self::assertCount(1, $this->mail->drafts);
        self::assertSame(1, $this->mail->appends);
    }
    public function testCrashImmediatelyBeforeAppendStaysConservative(): void
    {
        $this->mail->crashBeforeAppend = true;
        $this->runCommand();
        $this->mail->crashBeforeAppend = false;
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertSame('append_unknown', $this->record()->status);
        self::assertSame(1, $this->mail->appends);
        self::assertCount(0, $this->mail->drafts);
        $this->runCommand();
        self::assertSame(1, $this->mail->appends);
    }
    public function testRecoveryWorksAfterSourceLeavesInbox(): void
    {
        $this->mail->crashAfterAppend = true;
        $this->runCommand();
        $this->mail->messages = [];
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertSame('drafted', $this->record()->status);
    }
    public function testUnavailableReconciliationNeverRetriesAppend(): void
    {
        $this->mail->crashAfterAppend = true;
        $this->runCommand();
        $this->mail->failSearch = true;
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertSame('append_pending', $this->record()->status);
        self::assertSame(1, $this->mail->appends);
    }
    public function testDryRunMakesNoPersistentChanges(): void
    {
        $before = hash_file('sha256', $this->dir.'/state.sqlite');
        $t = $this->runCommand(['--dry-run' => true]);
        self::assertSame(0, $t->getStatusCode());
        self::assertSame(1, $this->ai->calls);
        self::assertStringContainsString('Zatwierdzona odpowiedź', $t->getDisplay());
        self::assertSame(0, $this->mail->appends);
        self::assertSame(0, $this->mail->flags);
        self::assertSame($before, hash_file('sha256', $this->dir.'/state.sqlite'));
        self::assertSame([], $this->em->getRepository(ProcessingRecord::class)->findAll());
        self::assertSame([], $this->em->getRepository(MailboxState::class)->findAll());
    }
    public function testDryRunDoesNotUpdatePendingRecovery(): void
    {
        $this->mail->crashAfterAppend = true;
        $this->runCommand();
        $before = hash_file('sha256', $this->dir.'/state.sqlite');
        $flags = $this->mail->flags;
        $this->runCommand(['--dry-run' => true]);
        self::assertSame('append_pending', $this->record()->status);
        self::assertSame($before, hash_file('sha256', $this->dir.'/state.sqlite'));
        self::assertSame($flags, $this->mail->flags);
    }
    public function testFlagFailureRetriesWithoutRegenerating(): void
    {
        $this->ai->proposal = Support::proposal(['outcome' => 'manual', 'blocks' => [], 'used_fact_ids' => [], 'reason' => 'requires_staff']);
        $this->mail->failFlag = true;
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertTrue($this->record()->flagPending);
        $this->mail->failFlag = false;
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertFalse($this->record()->flagPending);
        self::assertSame(1, $this->ai->calls);
    }
    public function testTransientFailuresRetryThreeTimesAndSanitizeErrors(): void
    {
        $this->ai->fail = true;
        for ($i = 0; $i < 3; ++$i) {
            $t = $this->runCommand();
            self::assertSame(1, $t->getStatusCode());
            self::assertStringNotContainsString('SECRET', $t->getDisplay());
        }
        $this->runCommand();
        self::assertSame(3, $this->ai->calls);
        self::assertSame('manual', $this->record()->status);
        self::assertStringNotContainsString('SECRET', file_get_contents($this->dir.'/state.sqlite'));
    }
    public function testUidValidityChangeStopsBeforeBodies(): void
    {
        $this->runCommand();
        $this->mail->validity = '2';
        $this->mail->messages[2] = Support::mime(2);
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertSame(1, $this->mail->bodyCalls);
        self::assertSame(1, $this->mail->appends);
    }
    public function testOverlappingRunDoesNoWork(): void
    {
        $state = new StateStore($this->em);
        $lock = (new LockFactory(new FlockStore($state->lockPath())))->createLock($state->lockKey());
        self::assertTrue($lock->acquire());
        try {
            $t = $this->runCommand();
            self::assertStringContainsString('holds the lock', $t->getDisplay());
            self::assertSame(0, $this->mail->connections);
        } finally {
            $lock->release();
        }
    }
    public function testLimitDoesNotStarveNewMessagesBehindCompletedRecords(): void
    {
        $this->runCommand();
        $this->mail->messages[2] = Support::mime(2);
        $this->mail->messages[3] = Support::mime(3);
        $this->runCommand(['--limit' => 1]);
        self::assertSame(2, $this->mail->appends);
        $this->runCommand(['--limit' => 1]);
        self::assertSame(3, $this->mail->appends);
    }
    public function testAutomatedAndSelfMailSkipBeforeBodies(): void
    {
        $this->mail->messages = [1 => str_replace('From: Client <client@example.test>', 'From: office@example.test', Support::mime()), 2 => str_replace('Subject: dokumenty', "Subject: dokumenty\r\nAuto-Submitted: auto-replied", Support::mime(2))];
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertSame(0, $this->mail->bodyCalls);
        self::assertSame(0, $this->ai->calls);
    }
    public function testInvalidAiOutputBecomesManualWithoutAppend(): void
    {
        $this->ai->proposal = Support::proposal(['used_price_ids' => ['invented']]);
        self::assertSame(1, $this->runCommand()->getStatusCode());
        self::assertSame('manual', $this->record()->status);
        self::assertSame(0, $this->mail->appends);
        self::assertSame(1, $this->mail->flags);
    }
    public function testDatabaseContainsMetadataOnly(): void
    {
        $this->runCommand();
        $raw = file_get_contents($this->dir.'/state.sqlite');
        self::assertStringNotContainsString('Proszę o dokumenty', $raw);
        self::assertStringNotContainsString('Zatwierdzona odpowiedź', $raw);
        self::assertSame(['fact:documents'], $this->record()->selectedIds);
    }
    public function testSqliteEnforcesUniqueSourceIdentity(): void
    {
        $this->runCommand();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement("INSERT INTO processing_record (id, mailbox, uid_validity, source_uid, source_message_id, status, attempts, draft_message_id, draft_uid, config_hash, selected_ids, reason, error, flag_pending, created_at, updated_at) SELECT 'different-primary-key', mailbox, uid_validity, source_uid, source_message_id, status, attempts, draft_message_id, draft_uid, config_hash, selected_ids, reason, error, flag_pending, created_at, updated_at FROM processing_record");
    }
    public function testTransientFailureCanRecoverWithoutKeepingFailureReason(): void
    {
        $this->ai->fail = true;
        $this->runCommand();
        $this->ai->fail = false;
        self::assertSame(0, $this->runCommand()->getStatusCode());
        self::assertSame('drafted', $this->record()->status);
        self::assertSame('supported', $this->record()->reason);
        self::assertSame(2, $this->record()->attempts);
        self::assertSame(1, $this->mail->appends);
    }
    public function testDryManualHandlingNeverFlags(): void
    {
        $this->mail->messages = [1 => Support::mime(body: 'dokumenty spór')];
        $before = hash_file('sha256', $this->dir.'/state.sqlite');
        $test = $this->runCommand(['--dry-run' => true]);
        self::assertStringContainsString('would set Flagged', $test->getDisplay());
        self::assertSame(0, $this->mail->flags);
        self::assertSame(0, $this->mail->appends);
        self::assertSame($before, hash_file('sha256', $this->dir.'/state.sqlite'));
    }
}
