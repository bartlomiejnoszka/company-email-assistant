<?php

declare(strict_types=1);

namespace App\Configuration\Domain;

final readonly class CompanyConfig
{
    public string $hash;
    public function __construct(public array $company, public array $knowledge, public array $pricing, public array $rules)
    {
        $this->hash = hash('sha256', json_encode(self::canonical([$company, $knowledge, $pricing, $rules]), JSON_THROW_ON_ERROR));
    }
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);
        return $value;
    }

}
