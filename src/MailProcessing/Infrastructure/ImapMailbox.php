<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Domain\ManualHandling;
use App\MailProcessing\Domain\MailboxEpochChanged;
use App\MailProcessing\Domain\SourceMessage;
use App\MailProcessing\Application\Port\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

final class ImapMailbox implements Mailbox
{
    private ?Client $client = null;
    private ?string $expectedValidity = null;
    public function __construct(private readonly string $host, private readonly int $port, private readonly string $username, #[\SensitiveParameter] private readonly string $password, private readonly string $drafts, private readonly string $inbox = 'INBOX')
    {
    }
    public function connect(): void
    {
        if ($this->host === '' || $this->username === '' || $this->password === '' || $this->port < 1 || $this->port > 65535 || trim($this->inbox) === '' || trim($this->drafts) === '' || strcasecmp($this->drafts, $this->inbox) === 0) {
            throw new \InvalidArgumentException('Set IMAP_HOST, IMAP_PORT, IMAP_USERNAME, IMAP_PASSWORD and a separate IMAP_DRAFTS_FOLDER');
        }
        $this->client = (new ClientManager(['options' => ['debug' => false]]))->make([
            'host' => $this->host, 'port' => $this->port, 'encryption' => 'ssl', 'validate_cert' => true,
            'username' => $this->username, 'password' => $this->password, 'protocol' => 'imap', 'timeout' => 30,
        ]);
        $this->client->connect();
        // EXAMINE verifies both folders exist without creating or changing them.
        $this->protocol()->examineFolder($this->drafts)->validatedData();
        $this->protocol()->examineFolder($this->inbox)->validatedData();
    }
    public function identity(): string
    {
        return hash('sha256', strtolower($this->host).':'.$this->port.'|'.$this->username.'|'.$this->inbox);
    }
    public function uidValidity(): string
    {
        $data = $this->protocol()->examineFolder($this->inbox)->validatedData();
        if (!isset($data['uidvalidity'])) {
            throw new \RuntimeException('missing_uidvalidity');
        }
        return $this->expectedValidity = (string) $data['uidvalidity'];
    }
    public function sources(\DateTimeImmutable $since): iterable
    {
        $p = $this->protocol();
        $this->checkEpoch($p->examineFolder($this->inbox)->validatedData());
        // SINCE has only day precision. Search one day earlier and compare exact INTERNALDATE below.
        $uids = $p->search(['SINCE', $since->modify('-1 day')->format('d-M-Y'), 'UNDELETED'])->validatedData();
        sort($uids, SORT_NUMERIC);
        foreach (array_chunk($uids, 100) as $chunk) {
            // A prior yielded message may have selected Drafts or selected Inbox read/write.
            $this->checkEpoch($p->examineFolder($this->inbox)->validatedData());
            $rows = $p->fetch(['UID', 'INTERNALDATE', 'RFC822.SIZE', 'FLAGS', 'BODY.PEEK[HEADER]'], $chunk)->validatedData();
            ksort($rows, SORT_NUMERIC);
            foreach ($rows as $uid => $row) {
                if (!isset($row['INTERNALDATE'], $row['RFC822.SIZE'], $row['BODY[HEADER]'])) {
                    throw new \RuntimeException('incomplete_imap_metadata');
                }
                if (new \DateTimeImmutable($row['INTERNALDATE']) < $since) {
                    continue;
                }
                yield new SourceMessage((int) $uid, $row['BODY[HEADER]'], (int) $row['RFC822.SIZE'], $row['FLAGS'] ?? []);
            }
        }
    }
    public function body(SourceMessage $source): string
    {
        if ($source->size > 2_000_000) {
            throw new ManualHandling('message_too_large');
        }
        $p = $this->protocol();
        $this->checkEpoch($p->examineFolder($this->inbox)->validatedData());
        $rows = $p->fetch(['UID', 'BODY.PEEK[TEXT]'], [$source->uid])->validatedData();
        if (!isset($rows[$source->uid]['BODY[TEXT]'])) {
            throw new \RuntimeException('missing_source_body');
        }
        return rtrim($source->headers)."\r\n\r\n".$rows[$source->uid]['BODY[TEXT]'];
    }
    public function append(string $mime): ?string
    {
        // A tagged successful APPEND is enough to make the record terminal. No automatic APPEND retry.
        $p = $this->protocol();
        $this->checkEpoch($p->examineFolder($this->inbox)->validatedData());
        $result = $p->appendMessage($this->drafts, $mime, ['\\Draft'])->validatedData();
        foreach ((array) $result as $line) {
            if (is_string($line) && preg_match('/^OK\s+\[APPENDUID\s+\d+\s+(\d+)\]/i', trim($line), $match)) {
                return $match[1];
            }
        }
        return null; // The stable MIME Message-ID is always recorded, even without UIDPLUS.
    }
    public function findDraft(string $messageId): array
    {
        $p = $this->protocol();
        $p->examineFolder($this->drafts)->validatedData();
        // Header search indexes may lag behind APPEND. Enumerate metadata for reliable reconciliation.
        $uids = $p->search(['ALL'])->validatedData();
        $matches = [];
        foreach (array_chunk($uids, 100) as $chunk) {
            $rows = $p->fetch(['UID', 'BODY.PEEK[HEADER]'], $chunk)->validatedData();
            foreach ($rows as $uid => $row) {
                if (!isset($row['BODY[HEADER]'])) {
                    throw new \RuntimeException('missing_draft_headers');
                }
                $source = new SourceMessage((int) $uid, $row['BODY[HEADER]'], 0);
                if ($source->header('Message-ID') === '<'.$messageId.'>') {
                    $matches[] = (string) $uid;
                }
            }
        }
        return $matches;
    }
    public function flag(int $uid): void
    {
        $p = $this->protocol();
        $this->checkEpoch($p->selectFolder($this->inbox)->validatedData());
        $p->store(['\\Flagged'], $uid)->validatedData();
    }
    public function disconnect(): void
    {
        $this->client?->disconnect();
    }
    private function checkEpoch(array $data): void
    {
        if ($this->expectedValidity !== null && (string) ($data['uidvalidity'] ?? '') !== $this->expectedValidity) {
            throw new MailboxEpochChanged('uidvalidity_changed_operator_reconciliation_required');
        }
    }
    private function protocol(): \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol
    {
        $protocol = $this->client?->getConnection();
        if (!$protocol instanceof \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol) {
            throw new \RuntimeException('Native IMAP connection required.');
        }
        return $protocol;
    }

}
