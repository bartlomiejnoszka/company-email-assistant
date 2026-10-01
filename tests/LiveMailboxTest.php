<?php

declare(strict_types=1);

namespace App\Tests;

use App\Reply\Infrastructure\OpenAiDraftAi;
use App\Interface\Console\GenerateDraftsCommand;
use App\MailProcessing\Domain\ProcessingRecord;
use App\MailProcessing\Infrastructure\ImapMailbox;
use App\MailProcessing\Infrastructure\ReplyComposer;
use App\MailProcessing\Infrastructure\TextExtractor;
use App\Configuration\Infrastructure\ConfigLoader;
use App\Reply\Domain\FactSelector;
use App\Reply\Domain\ProposalValidator;
use App\Reply\Domain\ReplyRenderer;
use App\MailProcessing\Infrastructure\StateStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__.'/Support.php';
#[Group('live')]
final class LiveMailboxTest extends TestCase
{
    public function testDedicatedMailboxDraftAndRerun(): void
    {
        if (getenv('OFFICE_LIVE_TEST') !== '1') {
            self::markTestSkipped('Opt-in live test; see README.');
        }
        (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
        $env = static fn (string $key): string => $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: '';
        self::assertNotSame('', $env('OFFICE_SMOKE_MAILBOX'), 'Explicitly name the dedicated test account.');
        self::assertSame($env('OFFICE_SMOKE_MAILBOX'), $env('IMAP_USERNAME'), 'Use a dedicated mailbox, never production.');
        self::assertNotSame('', $env('OFFICE_SMOKE_CONFIG_DIR'));
        $dir = sys_get_temp_dir().'/office-live-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $em = Support::em($dir.'/state.sqlite');
        $mail = new ImapMailbox($env('IMAP_HOST'), (int) $env('IMAP_PORT'), $env('IMAP_USERNAME'), $env('IMAP_PASSWORD'), $env('IMAP_DRAFTS_FOLDER'));
        try {
            $make = fn () => new CommandTester(new GenerateDraftsCommand(new \App\MailProcessing\Application\ProcessMailbox(
                new ConfigLoader($env('OFFICE_SMOKE_CONFIG_DIR')),
                $mail,
                new OpenAiDraftAi($env('OPENAI_API_KEY'), $env('OPENAI_MODEL')),
                new StateStore($em),
                new TextExtractor(),
                new \App\Reply\Application\ResponseGenerator(new OpenAiDraftAi($env('OPENAI_API_KEY'), $env('OPENAI_MODEL')), new FactSelector(), new ProposalValidator(), new ReplyRenderer()),
                new ReplyComposer(),
                $env('EMAIL_START_AT'),
                new \App\MailProcessing\Infrastructure\FlockRunLock(new StateStore($em)),
            )));
            $test = $make();
            $test->execute(['--limit' => 1]);
            self::assertSame(0, $test->getStatusCode(), $test->getDisplay());
            $records = $em->getRepository(ProcessingRecord::class)->findAll();
            self::assertCount(1, $records, 'Seed exactly one eligible email in the test Inbox.');
            self::assertSame('drafted', $records[0]->status, 'Use a question answerable from the supplied approved test blocks.');
            $draftId = $records[0]->draftMessageId;
            $mail->connect();
            self::assertCount(1, $mail->findDraft($draftId));
            $mail->disconnect();
            $test = $make();
            $test->execute(['--limit' => 1]);
            self::assertSame(0, $test->getStatusCode(), $test->getDisplay());
            self::assertCount(1, $em->getRepository(ProcessingRecord::class)->findAll());
            $mail->connect();
            self::assertCount(1, $mail->findDraft($draftId));
            // Leave the draft in place for the required manual email-client check.
        } finally {
            $mail->disconnect();
            $em->getConnection()->close();
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }
}
