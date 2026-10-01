<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth;

use App\Identity\Infrastructure\OAuth\Entity\Owner;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Security\Core\{Authentication\Token\TokenInterface, Exception\AuthenticationException, Exception\CustomUserMessageAuthenticationException};
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\{Passport, SelfValidatingPassport};
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class McpAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly ServerFactory $servers, private readonly Settings $settings)
    {
    }
    public function supports(Request $request): bool
    {
        return $request->getPathInfo() === '/mcp';
    }
    public function authenticate(Request $request): Passport
    {
        try {
            $psr = new Psr17Factory();
            $validated = $this->servers->resource()->validateAuthenticatedRequest((new PsrHttpFactory($psr, $psr, $psr, $psr))->createRequest($request));
            // League validates signature, lifetime and revocation; enforce the resource audience and issuer as well.
            $header = $request->headers->get('Authorization', '');
            $jwt = (new Parser(new JoseEncoder()))->parse(substr($header, 7));
            if (!$jwt instanceof \Lcobucci\JWT\UnencryptedToken) {
                throw new \RuntimeException('Expected signed token');
            }
            if ($jwt->claims()->get('iss') !== $this->settings->baseUrl || $jwt->claims()->get('aud') !== [$this->settings->resource()] || $validated->getAttribute('oauth_user_id') !== 'owner') {
                throw new \RuntimeException('Invalid token binding');
            }
            $scopes = $validated->getAttribute('oauth_scopes');
            if (!is_array($scopes) || array_diff($scopes, Repository::SCOPES)) {
                throw new \RuntimeException('Invalid scopes');
            }
            return new SelfValidatingPassport(new UserBadge('owner', static fn () => new Owner($scopes)));
        } catch (\Throwable) {
            throw new CustomUserMessageAuthenticationException('A valid owner OAuth token is required.');
        }
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="'.$this->settings->baseUrl.'/.well-known/oauth-protected-resource", scope="configuration:read configuration:write"', 'Cache-Control' => 'no-store']);
    }
}
