<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

final readonly class ExtractedText
{
    public function __construct(public string $text, public bool $attachments)
    {
    }
}
