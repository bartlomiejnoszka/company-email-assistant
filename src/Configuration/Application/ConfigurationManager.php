<?php

declare(strict_types=1);

namespace App\Configuration\Application;

use App\Configuration\Application\Port\ConfigurationStorage;
use App\Configuration\Domain\CompanyConfig;

final class ConfigurationManager
{
    public function __construct(private readonly ConfigurationStorage $storage)
    {
    }
    public function read(): array
    {
        return $this->storage->read();
    }
    public function loadFiles(array $files): CompanyConfig
    {
        return $this->storage->loadFiles($files);
    }
    public function validate(array $files): void
    {
        $this->storage->validate($files);
    }
    public function history(): array
    {
        return $this->storage->history();
    }
    public function save(array $files, string $expectedVersion): string
    {
        return $this->storage->save($files, $expectedVersion);
    }
    public function restore(string $expectedVersion): string
    {
        return $this->storage->restore($expectedVersion);
    }
}
