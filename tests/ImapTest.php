<?php

declare(strict_types=1);

namespace App\Tests;

use App\MailProcessing\Infrastructure\ImapMailbox;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\{ImapProtocol, Response};

require_once __DIR__.'/Support.php';
final class ImapTest extends TestCase
{
    private function response(mixed $value): Response
    {
        return (new Response(1))->setResult($value)->setCanBeEmpty(true);
    }
    private function mailbox(ImapProtocol $protocol): ImapMailbox
    {
        $client = new Client(\Webklex\PHPIMAP\Config::make([]));
        $protocol->method('connected')->willReturn(true);
        $client->connection = $protocol;
        $mail = new ImapMailbox('imap.example.test', 993, 'test', 'secret', 'Drafts');
        (new \ReflectionProperty(ImapMailbox::class, 'client'))->setValue($mail, $client);
        return $mail;
    }
    public function testInternalReceiptTimeAndPeekFetching(): void
    {
        $p = $this->createMock(ImapProtocol::class);
        $p->method('examineFolder')->with('INBOX')->willReturn($this->response(['uidvalidity' => '1']));
        $p->expects(self::once())->method('search')->with(['SINCE', '19-Sep-2026', 'UNDELETED'])->willReturn($this->response([1, 2]));
        $headers = Support::source(Support::mime())->headers;
        $p->expects(self::exactly(2))->method('fetch')->willReturnCallback(function ($items, $uids) use ($headers) {
            if (in_array('BODY.PEEK[HEADER]', $items, true)) {
                return $this->response([
                    1 => ['UID' => 1, 'INTERNALDATE' => '20-Sep-2026 09:59:59 +0200', 'RFC822.SIZE' => 200, 'BODY[HEADER]' => $headers],
                    2 => ['UID' => 2, 'INTERNALDATE' => '20-Sep-2026 10:00:00 +0200', 'RFC822.SIZE' => 200, 'BODY[HEADER]' => $headers, 'FLAGS' => ['\\Seen']],
                ]);
            }
            self::assertSame(['UID', 'BODY.PEEK[TEXT]'], $items);
            self::assertSame([2], $uids);
            return $this->response([2 => ['UID' => 2, 'BODY[TEXT]' => 'body']]);
        });
        $mail = $this->mailbox($p);
        $sources = iterator_to_array($mail->sources(new \DateTimeImmutable('2026-09-20T10:00:00+02:00')));
        self::assertCount(1, $sources);
        self::assertSame(2, $sources[0]->uid);
        self::assertSame(['\\Seen'], $sources[0]->flags);
        self::assertStringEndsWith("\r\n\r\nbody", $mail->body($sources[0]));
    }
    public function testAppendUsesDraftFlagAndFlaggingPreservesOtherFlags(): void
    {
        $p = $this->createMock(ImapProtocol::class);
        $p->method('examineFolder')->with('INBOX')->willReturn($this->response(['uidvalidity' => '1']));
        $p->expects(self::once())->method('appendMessage')->with('Drafts', 'MIME', ['\\Draft'])->willReturn($this->response(['OK [APPENDUID 2 42] Append completed']));
        $p->expects(self::once())->method('selectFolder')->with('INBOX')->willReturn($this->response([]));
        $p->expects(self::once())->method('store')->with(['\\Flagged'], 42)->willReturn($this->response([]));
        $mail = $this->mailbox($p);
        self::assertSame('42', $mail->append('MIME'));
        $mail->flag(42);
    }
    public function testDraftSearchVerifiesExactMessageId(): void
    {
        $p = $this->createMock(ImapProtocol::class);
        $p->method('examineFolder')->with('Drafts')->willReturn($this->response([]));
        $p->method('search')->with(['ALL'])->willReturn($this->response([1, 2]));
        $p->method('fetch')->willReturn($this->response([1 => ['BODY[HEADER]' => 'Message-ID: <draft@example.test>'], 2 => ['BODY[HEADER]' => 'Message-ID: <prefix-draft@example.test>']]));
        self::assertSame(['1'], $this->mailbox($p)->findDraft('draft@example.test'));
    }
    public function testEpochChangeBeforeFlagPreventsMutation(): void
    {
        $p = $this->createMock(ImapProtocol::class);
        $p->method('examineFolder')->willReturn($this->response(['uidvalidity' => '1']));
        $p->method('selectFolder')->willReturn($this->response(['uidvalidity' => '2']));
        $p->expects(self::never())->method('store');
        $mail = $this->mailbox($p);
        $mail->uidValidity();
        $this->expectException(\App\MailProcessing\Domain\MailboxEpochChanged::class);
        $mail->flag(1);
    }
}
