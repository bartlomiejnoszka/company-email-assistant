<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\{EntityTrait, ScopeTrait};

final class Scope implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;
    public function __construct(string $id)
    {
        $this->setIdentifier($id);
    }
}
