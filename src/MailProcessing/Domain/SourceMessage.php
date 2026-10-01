<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

final readonly class SourceMessage
{
    public function __construct(public int $uid, public string $headers, public int $size, public array $flags = [])
    {
    }
    public function header(string $name): ?string
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', trim($this->headers));
        preg_match_all('/^'.preg_quote($name, '/').':[ \t]*(.*)$/mi', $unfolded, $matches);
        if (count($matches[1]) > 1) {
            throw new ManualHandling('duplicate_header');
        }
        return isset($matches[1][0]) ? trim($matches[1][0]) : null;
    }
    public function subject(): string
    {
        return mb_decode_mimeheader($this->header('Subject') ?? '');
    }
}
