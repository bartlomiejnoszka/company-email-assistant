<?php

declare(strict_types=1);

namespace App\Interface\Http;

use App\Configuration\Application\Port\ConfigProvider;

final class CompanyBrand
{
    public function __construct(private readonly ConfigProvider $configuration)
    {
    }
    public function getName(): string
    {
        try {
            return $this->configuration->load()->company['name'];
        } catch (\Throwable) {
            return 'Company Email Assistant';
        }
    }
}
