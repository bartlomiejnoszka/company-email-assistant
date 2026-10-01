<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class Owner implements UserEntityInterface, UserInterface
{
    public function __construct(private readonly array $scopes = [])
    {
    }
    public function getIdentifier(): string
    {
        return 'owner';
    }
    public function getUserIdentifier(): string
    {
        return 'owner';
    }
    public function getRoles(): array
    {
        $roles = [];
        if (in_array('configuration:read', $this->scopes, true)) {
            $roles[] = 'ROLE_CONFIG_READ';
        }
        if (in_array('configuration:write', $this->scopes, true)) {
            $roles[] = 'ROLE_CONFIG_WRITE';
        }
        return $roles;
    }
    public function eraseCredentials(): void
    {
    }
}
