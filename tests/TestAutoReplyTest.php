<?php

declare(strict_types=1);

namespace App\Tests;

use App\MailProcessing\Domain\ProcessingRecord;
use App\MailProcessing\Application\Port\ReplySender;
use App\MailProcessing\Domain\TestAutoReply;
use App\MailProcessing\Infrastructure\SmtpReplySender;
use App\MailProcessing\Infrastructure\StateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use App\MailProcessing\Domain\EmailAddress as Address;

require_once __DIR__.'/Support.php';

final class TestAutoReplyTest extends TestCase
{
    public function testOnlySingleExplicitToMatches(): void
    {
        $policy = new TestAutoReply(true, [Support::TEST_RECIPIENT]);
        foreach ([Support::TEST_RECIPIENT, 'Kancelaria <'.Support::TEST_RECIPIENT.'>', strtoupper(Support::TEST_RECIPIENT)] as $to) {
            self::assertTrue($policy->matches(Support::source(str_replace('To: office@example.test', 'To: '.$to, Support::mime()))));
        }
        foreach (['office@example.test', Support::TEST_RECIPIENT.'.evil.test', Support::TEST_RECIPIENT.', other@example.test', '"'.Support::TEST_RECIPIENT.'" <other@example.test>', ''] as $to) {
            self::assertFalse($policy->matches(Support::source(str_replace('To: office@example.test', 'To: '.$to."\r\nCc: ".Support::TEST_RECIPIENT, Support::mime()))));
        }
        self::assertFalse((new TestAutoReply())->matches(Support::source(str_replace('office@example.test', Support::TEST_RECIPIENT, Support::mime()))));
    }
    public function testCommandDeliveryAndRecovery(): void
    {
        $dir = sys_get_temp_dir().'/test-send-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $em = Support::em($dir.'/state.sqlite');
        $loader = Support::writeConfig($dir);
        $mail = new FakeMailbox();
        $ai = new FakeAi();
        $sender = new FakeSender();
        $state = new StateStore($em);
        $run = function (array $options = [], bool $enabled = true) use ($loader, $mail, $ai, $em, $sender): CommandTester {
            $t = new CommandTester(Support::command($loader, $mail, $ai, $em, $sender, $enabled));
            $t->execute($options);
            return $t;
        };
        try {
            $mail->messages = [1 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime())];
            $before = hash_file('sha256', $dir.'/state.sqlite');
            self::assertSame(0, $run(['--dry-run' => true])->getStatusCode());
            self::assertSame($before, hash_file('sha256', $dir.'/state.sqlite'));
            self::assertCount(0, $sender->sent);
            self::assertSame(0, $mail->flags);
            $sender->beforeSend = function () use ($state, $mail): void {
                self::assertSame('send_pending', $state->find($mail->identity(), '1', 1)->status);
            };
            self::assertSame(0, $run()->getStatusCode());
            $sender->beforeSend = null;
            self::assertSame('sent', $state->find($mail->identity(), '1', 1)->status);
            self::assertSame(0, $mail->appends);
            self::assertCount(1, $sender->sent);
            self::assertStringContainsString('Auto-Submitted: auto-replied', $sender->sent[0]);
            self::assertStringContainsString('In-Reply-To: <source-1@example.test>', $sender->sent[0]);
            self::assertSame('client@example.test', $sender->recipient);
            $em->clear();
            $run();
            self::assertCount(1, $sender->sent);
            // Normal Inbox mail retains drafts.
            $mail->messages = [2 => Support::mime(2)];
            $run();
            self::assertSame(1, $mail->appends);
            // Failure after SMTP may have accepted: never retry.
            $mail->messages = [3 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime(3))];
            $sender->fail = true;
            self::assertSame(1, $run()->getStatusCode());
            self::assertSame('send_unknown', $state->find($mail->identity(), '1', 3)->status);
            $sender->fail = false;
            $em->clear();
            $run();
            self::assertCount(2, $sender->sent);
            // Simulate process death at boundary, even when original message has disappeared.
            $pending = new ProcessingRecord($mail->identity(), '1', 4, $loader->load()->hash);
            $pending->status = 'send_pending';
            $state->save($pending);
            $mail->messages = [];
            self::assertSame(1, $run()->getStatusCode());
            self::assertSame('send_unknown', $pending->status);
            self::assertCount(2, $sender->sent);
            // Reply-To pointing at the same mailbox cannot create a loop.
            $mail->messages = [5 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT."\r\nReply-To: test+other@example.test", Support::mime(5))];
            $run();
            self::assertSame('auto_reply_loop', $state->find($mail->identity(), '1', 5)->reason);
            self::assertCount(2, $sender->sent);
            // Automated incoming messages remain skipped.
            $mail->messages = [6 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT."\r\nAuto-Submitted: auto-replied", Support::mime(6))];
            $run();
            self::assertSame('skipped', $state->find($mail->identity(), '1', 6)->status);
            self::assertCount(2, $sender->sent);
            // Failures before the sending boundary can retry; invalid content cannot send.
            $mail->messages = [8 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime(8))];
            $ai->fail = true;
            self::assertSame(1, $run()->getStatusCode());
            self::assertCount(2, $sender->sent);
            $ai->fail = false;
            self::assertSame(0, $run()->getStatusCode());
            self::assertCount(3, $sender->sent);
            $mail->messages = [9 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime(9))];
            $ai->proposal = Support::proposal(['blocks' => ['fact:invented']]);
            self::assertSame(1, $run()->getStatusCode());
            self::assertCount(3, $sender->sent);
            $ai->proposal = null;
            // Pending flags retry without repeating an uncertain send, even with auto-send disabled.
            $mail->messages = [10 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime(10))];
            $sender->fail = true;
            $mail->failFlag = true;
            $run();
            self::assertTrue($state->find($mail->identity(), '1', 10)->flagPending);
            $sender->fail = false;
            $mail->failFlag = false;
            $run([], false);
            self::assertFalse($state->find($mail->identity(), '1', 10)->flagPending);
            self::assertCount(4, $sender->sent);
            // Disabled mode never invokes SMTP preflight and still makes a draft.
            $sender->preflightFail = true;
            $mail->messages = [7 => str_replace('To: office@example.test', 'To: '.Support::TEST_RECIPIENT, Support::mime(7))];
            self::assertSame(0, $run([], false)->getStatusCode());
            self::assertSame(2, $mail->appends);
            $connections = $mail->connections;
            self::assertSame(1, $run()->getStatusCode());
            self::assertSame($connections, $mail->connections);
        } finally {
            $em->getConnection()->close();
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }
    public function testSmtpTlsConfiguration(): void
    {
        (new SmtpReplySender('smtps://user:password@mail.example.test:465'))->preflight();
        foreach (['', 'smtp://mail.example.test', 'smtps://mail.example.test?verify_peer=0', 'null://null', 'sendmail://default'] as $dsn) {
            try {
                (new SmtpReplySender($dsn))->preflight();
                self::fail('Accepted unsafe DSN');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
final class FakeSender implements ReplySender
{
    public array $sent = [];
    public string $recipient = '';
    public bool $fail = false;
    public bool $preflightFail = false;
    public ?\Closure $beforeSend = null;
    public function preflight(): void
    {
        if ($this->preflightFail) {
            throw new \RuntimeException('secret');
        }
    }
    public function send(string $mime, Address $from, Address $to): void
    {
        if ($this->beforeSend) {
            ($this->beforeSend)();
        }
        $this->sent[] = $mime;
        $this->recipient = $to->getAddress();
        if ($this->fail) {
            throw new \RuntimeException('secret');
        }
    }
}
