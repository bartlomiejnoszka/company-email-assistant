<?php

declare(strict_types=1);

namespace App\Configuration\Application\Port;

use App\Configuration\Domain\CompanyConfig;

interface ConfigProvider
{
    public function load(): CompanyConfig;
}
