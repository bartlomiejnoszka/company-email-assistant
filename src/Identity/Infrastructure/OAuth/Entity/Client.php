<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\{EntityTrait, ClientTrait};

final class Client implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;
    public function __construct(string $id, array $redirects, bool $confidential = false)
    {
        $this->identifier = $id;
        $this->name = 'ChatGPT';
        $this->redirectUri = $redirects;
        $this->isConfidential = $confidential;
    }
}
