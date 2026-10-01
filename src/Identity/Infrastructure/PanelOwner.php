<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use Symfony\Component\Security\Core\User\{UserInterface, PasswordAuthenticatedUserInterface};

final readonly class PanelOwner implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private string $passwordHash)
    {
    }
    public function getUserIdentifier(): string
    {
        return 'owner';
    }
    public function getRoles(): array
    {
        return ['ROLE_OWNER'];
    }
    public function getPassword(): string
    {
        return $this->passwordHash;
    }
    public function eraseCredentials(): void
    {
    }
}
