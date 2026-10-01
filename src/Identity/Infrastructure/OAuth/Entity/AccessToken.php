<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth\Entity;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\{EntityTrait, TokenEntityTrait};
use League\OAuth2\Server\CryptKeyInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;
    private CryptKeyInterface $privateKey;
    public function __construct(private readonly string $issuer, private readonly string $resource)
    {
    }
    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }
    public function toString(): string
    {
        $jwt = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($this->privateKey->getKeyContents()), InMemory::plainText('unused'));
        $now = new \DateTimeImmutable();
        return $jwt->builder()->issuedBy($this->issuer)->permittedFor($this->resource)->identifiedBy($this->identifier)
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($this->expiryDateTime)->relatedTo($this->userIdentifier ?? 'owner')
            ->withClaim('client_id', $this->client->getIdentifier())->withClaim('scopes', $this->getScopes())
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
    }
}
