<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Domain\ManualHandling;
use App\MailProcessing\Domain\SourceMessage;
use App\Configuration\Domain\CompanyConfig;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ReplyComposer implements \App\MailProcessing\Application\Port\MimeComposer
{
    public function sourceMessageId(SourceMessage $source): string
    {
        $id = $source->header('Message-ID') ?? '';
        if (!preg_match('/^<([^<>\s@]+@[^<>\s@]+)>$/D', $id, $match) || strlen($id) > 254) {
            throw new ManualHandling('invalid_message_id');
        }
        return $match[1];
    }
    public function recipient(SourceMessage $source): \App\MailProcessing\Domain\EmailAddress
    {
        $raw = $source->header('Reply-To') ?? $source->header('From') ?? '';
        if (str_contains($raw, "\n") || str_contains($raw, "\r")) {
            throw new ManualHandling('invalid_recipient');
        }
        try {
            $address = Address::create(mb_decode_mimeheader($raw));
            if (!filter_var($address->getAddress(), FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException();
            }
            // Address::create accepts trailing data after a bracketed address; reject multiple recipients.
            if (str_contains($raw, '<') && !preg_match('/^(?:"[^"\r\n]*"|[^<>]*)<[^<>]+>\s*$/D', $raw)) {
                throw new \InvalidArgumentException();
            }
            return new \App\MailProcessing\Domain\EmailAddress($address->getAddress(), $address->getName());
        } catch (\Throwable) {
            throw new ManualHandling('invalid_recipient');
        }
    }
    public function skip(SourceMessage $source, CompanyConfig $config): bool
    {
        $auto = strtolower($source->header('Auto-Submitted') ?? 'no');
        if ($auto !== 'no' || preg_match('/^(bulk|list|junk)$/i', $source->header('Precedence') ?? '') || $source->header('List-ID') !== null || $source->header('Return-Path') === '<>') {
            return true;
        }
        try {
            $from = Address::create(mb_decode_mimeheader($source->header('From') ?? ''));
        } catch (\Throwable) {
            throw new ManualHandling('invalid_sender');
        }
        return strcasecmp($from->getAddress(), $config->company['sender_address']) === 0;
    }
    public function compose(SourceMessage $source, CompanyConfig $config, string $body, string $draftId, bool $automatic = false): string
    {
        $sourceId = $this->sourceMessageId($source);
        $refs = [];
        $rawRefs = $source->header('References') ?? '';
        if ($rawRefs !== '') {
            preg_match_all('/<([^<>\s@]+@[^<>\s@]+)>/', $rawRefs, $matches);
            if (trim(preg_replace('/<[^<>\s@]+@[^<>\s@]+>/', '', $rawRefs)) !== '') {
                throw new ManualHandling('invalid_references');
            }
            $refs = $matches[1];
        } elseif (($parent = $source->header('In-Reply-To')) !== null) {
            if (!preg_match('/^<([^<>\s@]+@[^<>\s@]+)>$/D', $parent, $match)) {
                throw new ManualHandling('invalid_references');
            }
            $refs[] = $match[1];
        }
        $refs[] = $sourceId;
        if (count($refs) > 100) {
            throw new ManualHandling('excessive_thread_headers');
        }
        $subject = preg_replace('/^(?:(?:re|odp):\s*)+/iu', '', $source->subject());
        if (preg_match('/[\r\n\x00]/', $subject)) {
            throw new ManualHandling('invalid_subject');
        }
        // Supply HTML explicitly: some draft editors wrap plain text in a div
        // without converting newlines, which collapses paragraphs after sending.
        $html = '<div>'.str_replace("\n", "<br>\n", htmlspecialchars(
            str_replace(["\r\n", "\r"], "\n", $body),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )).'</div>';
        $recipient = $this->recipient($source);
        $email = (new Email())->from(new Address($config->company['sender_address'], $config->company['name']))
            ->to(new Address($recipient->getAddress(), $recipient->getName()))->subject('Re: '.$subject)->text($body, 'utf-8')->html($html, 'utf-8');
        $email->getHeaders()->addIdHeader('Message-ID', $draftId);
        $email->getHeaders()->addIdHeader('In-Reply-To', $sourceId);
        $email->getHeaders()->addIdHeader('References', array_values(array_unique($refs)));
        if ($automatic) {
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        }
        return $email->toString();
    }
}
