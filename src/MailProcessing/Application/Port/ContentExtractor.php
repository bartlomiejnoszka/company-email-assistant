<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

use App\MailProcessing\Domain\ExtractedText;

interface ContentExtractor
{
    public function extract(string $mime): ExtractedText;
}
