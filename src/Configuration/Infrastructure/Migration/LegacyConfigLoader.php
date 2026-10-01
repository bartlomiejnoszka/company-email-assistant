<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure\Migration;

use App\Configuration\Domain\{ConfigValidator, CompanyConfig};
use App\Configuration\Infrastructure\ConfigSnapshotStore;

final class LegacyConfigLoader implements \App\Configuration\Application\Port\ConfigProvider
{
    public function __construct(private readonly string $directory)
    {
    }
    public function load(): CompanyConfig
    {
        $reader = new PolishConfigReader();
        $data = $reader->read(ConfigSnapshotStore::resolveDirectory($this->directory));
        // Validate old source paths first; convert only editing/matching identifiers.
        $data = LegacyVocabulary::convert($data);
        try {
            return (new ConfigValidator())->validate($data);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($reader->sourceError($e->getMessage()), previous: $e);
        }
    }
}
