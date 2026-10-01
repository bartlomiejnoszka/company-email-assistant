<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure;

use App\Configuration\Application\Port\ConfigProvider;
use App\Configuration\Domain\CompanyConfig;

final class ConfigLoader implements ConfigProvider
{
    public function __construct(private readonly string $directory)
    {
    }
    public function load(): CompanyConfig
    {
        return (new GenericConfigReader())->read(ConfigSnapshotStore::resolveDirectory($this->directory));
    }
}
