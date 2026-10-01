<?php

declare(strict_types=1);

namespace App\Configuration\Application\Port;

use App\Configuration\Domain\CompanyConfig;

interface ConfigurationStorage
{
    public function read(): array;
    public function loadFiles(array $files): CompanyConfig;
    public function validate(array $files): void;
    public function history(): array;
    public function save(array $files, string $expectedVersion): string;
    public function restore(string $expectedVersion): string;
}
