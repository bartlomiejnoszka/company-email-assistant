<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Application\Port\ReplySender;
use Symfony\Component\Mailer\{Envelope, Transport};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\{Address, RawMessage};

final class SmtpReplySender implements ReplySender
{
    private ?TransportInterface $transport = null;
    public function __construct(private readonly string $dsn)
    {
    }
    public function preflight(): void
    {
        // Only verified implicit TLS is accepted; never expose the DSN in errors.
        $parts = parse_url($this->dsn);
        parse_str($parts['query'] ?? '', $options);
        if (!$parts || ($parts['scheme'] ?? '') !== 'smtps' || empty($parts['host']) || $options) {
            throw new \InvalidArgumentException('SMTP_DSN must use smtps with no query options.');
        }
        $this->transport = Transport::fromDsn($this->dsn);
    }
    public function send(string $mime, \App\MailProcessing\Domain\EmailAddress $from, \App\MailProcessing\Domain\EmailAddress $to): void
    {
        if (!$this->transport) {
            throw new \LogicException('SMTP preflight required.');
        }
        $this->transport->send(new RawMessage($mime), new Envelope(new Address($from->getAddress(), $from->getName()), [new Address($to->getAddress(), $to->getName())]));
    }
}
