<?php

declare(strict_types=1);

namespace App\Reply\Application\Port;

use App\Configuration\Domain\CompanyConfig;
use App\Reply\Domain\Selection;

interface DraftAi
{
    public function preflight(): void;
    public function matchTopics(string $text, CompanyConfig $config): array;
    /** Rewrite only the supplied editable blocks; return structured edits, never a full MIME reply. */
    public function rewrite(string $text, array $editable, CompanyConfig $config): array;
    public function propose(string $text, Selection $selection, CompanyConfig $config): array;
}
