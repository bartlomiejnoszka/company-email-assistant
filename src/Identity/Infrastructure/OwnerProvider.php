<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Infrastructure\OAuth\Settings;
use Symfony\Component\Security\Core\User\{UserInterface, UserProviderInterface};
use Symfony\Component\Security\Core\Exception\{UserNotFoundException, UnsupportedUserException};

/** @implements UserProviderInterface<PanelOwner> */
final class OwnerProvider implements UserProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }
    public function loadUserByIdentifier(string $identifier): PanelOwner
    {
        if ($identifier !== 'owner') {
            throw new UserNotFoundException();
        }
        try {
            return new PanelOwner($this->settings->read()['owner_password_hash']);
        } catch (\Throwable) {
            throw new UserNotFoundException('Owner is not configured.');
        }
    }
    public function refreshUser(UserInterface $user): PanelOwner
    {
        if (!$user instanceof PanelOwner) {
            throw new UnsupportedUserException();
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }
    public function supportsClass(string $class): bool
    {
        return $class === PanelOwner::class;
    }
}
