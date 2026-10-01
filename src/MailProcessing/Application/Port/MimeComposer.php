<?php

declare(strict_types=1);

namespace App\MailProcessing\Application\Port;

use App\Configuration\Domain\CompanyConfig;
use App\MailProcessing\Domain\{EmailAddress, SourceMessage};

interface MimeComposer
{
    public function sourceMessageId(SourceMessage $source): string;
    public function recipient(SourceMessage $source): EmailAddress;
    public function skip(SourceMessage $source, CompanyConfig $config): bool;
    public function compose(SourceMessage $source, CompanyConfig $config, string $body, string $draftId, bool $automatic = false): string;
}
