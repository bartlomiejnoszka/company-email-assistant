<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Infrastructure\OAuth\Repository;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\{CsrfTokenBadge, UserBadge};
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;

final class OwnerAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(private readonly Repository $repository, private readonly UrlGeneratorInterface $urls)
    {
    }
    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'owner_login' && $request->isMethod('POST');
    }
    public function authenticate(Request $request): Passport
    {
        return new Passport(new UserBadge('owner'), new CustomCredentials(
            function (string $password): bool {
                try {
                    return $this->repository->attemptLogin($password);
                } catch (\Throwable) {
                    return false;
                }
            },
            (string) $request->request->get('password', '')
        ), [new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token', ''))]);
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->urls->generate('panel_config'), 303);
    }
    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('owner_login');
    }
}
