<?php

declare(strict_types=1);

namespace App\MailProcessing\Infrastructure;

use App\MailProcessing\Domain\ManualHandling;
use App\MailProcessing\Domain\ExtractedText;
use Webklex\PHPIMAP\Message;

final class TextExtractor implements \App\MailProcessing\Application\Port\ContentExtractor
{
    public function extract(string $mime): ExtractedText
    {
        if (strlen($mime) > 2_000_000) {
            throw new ManualHandling('message_too_large');
        }
        try {
            $message = Message::fromString($mime);
        } catch (\Throwable) {
            throw new ManualHandling('invalid_mime');
        }
        $plain = $message->getTextBody();
        if (trim($plain) === '') {
            $plain = $this->html($message->getHTMLBody());
        }
        if (!mb_check_encoding($plain, 'UTF-8')) {
            throw new ManualHandling('invalid_encoding');
        }
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $lines = explode("\n", $plain);
        $kept = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:On .+wrote:|W dniu .+napisa[łl](?:a)?:|Dnia .+napisa[łl](?:a)?:|[-_]{2,}\s*(?:Original Message|Wiadomość oryginalna|Forwarded message)|Begin forwarded message:|--\s*$)/iu', trim($line))) {
                break;
            }
            if (preg_match('/^\s*>/', $line)) {
                // Do not silently discard a later inline answer.
                foreach (array_slice($lines, $i + 1) as $rest) {
                    if (trim($rest) !== '' && !preg_match('/^\s*>/', $rest)) {
                        throw new ManualHandling('ambiguous_quoted_reply');
                    }
                }
                break;
            }
            if (preg_match('/^(?:From|Od):\s*.+/iu', $line) && preg_match('/^(?:Sent|Wysłano|Date|Data|To|Do):/imu', implode("\n", array_slice($lines, $i + 1, 4)))) {
                break;
            }
            $kept[] = $line;
        }
        $text = trim(implode("\n", $kept));
        if ($text === '') {
            throw new ManualHandling('empty_message');
        }
        if (mb_strlen($text) > 32_000) {
            throw new ManualHandling('text_too_large');
        }
        return new ExtractedText($text, $message->getAttachments()->count() > 0);
    }
    private function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//script|//style|//head|//iframe|//object') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//blockquote|//*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]|//*[@id="divRplyFwdMsg"]') as $node) {
                $node->parentNode?->replaceChild($dom->createTextNode("\n----- Original Message -----\n"), $node);
            }
            foreach ($xpath->query('//br') as $node) {
                $node->parentNode?->replaceChild($dom->createTextNode("\n"), $node);
            }
            foreach ($xpath->query('//p|//div|//li|//tr') as $node) {
                $node->appendChild($dom->createTextNode("\n"));
            }
            return html_entity_decode($dom->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }
}
