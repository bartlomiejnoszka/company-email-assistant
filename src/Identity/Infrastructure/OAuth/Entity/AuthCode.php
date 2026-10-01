<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth\Entity;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\{EntityTrait, TokenEntityTrait, AuthCodeTrait};

final class AuthCode implements AuthCodeEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;
    use AuthCodeTrait;
}
