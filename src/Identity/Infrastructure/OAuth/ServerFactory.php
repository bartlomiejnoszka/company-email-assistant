<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth;

use League\OAuth2\Server\{AuthorizationServer, CryptKey, ResourceServer};
use League\OAuth2\Server\Grant\{AuthCodeGrant, RefreshTokenGrant};

final class ServerFactory
{
    public function __construct(private readonly Settings $settings, private readonly Repository $repository)
    {
    }
    public function authorization(): AuthorizationServer
    {
        $config = $this->settings->read();
        $server = new AuthorizationServer($this->repository, $this->repository, $this->repository, new CryptKey($this->settings->directory.'/private.key'), $config['encryption_key']);
        $grant = new AuthCodeGrant($this->repository, $this->repository, new \DateInterval('PT5M'));
        $grant->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($grant, new \DateInterval('PT1H'));
        $refresh = new RefreshTokenGrant($this->repository);
        $refresh->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($refresh, new \DateInterval('PT1H'));
        return $server;
    }
    public function resource(): ResourceServer
    {
        $this->settings->read();
        return new ResourceServer($this->repository, new CryptKey($this->settings->directory.'/public.key'));
    }
}
