<?php

declare(strict_types=1);

namespace App\Interface\Http;

use App\Identity\Infrastructure\OAuth\Settings;
use App\Identity\Infrastructure\OAuth\Repository;
use App\Identity\Infrastructure\OAuth\ServerFactory;
use App\Identity\Infrastructure\OAuth\Entity\Owner;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\{PsrHttpFactory, HttpFoundationFactory};
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};
use Twig\Environment;

final class OAuthController
{
    public function __construct(private readonly Settings $settings, private readonly Repository $repository, private readonly ServerFactory $servers, private readonly CsrfTokenManagerInterface $csrf, private readonly Environment $twig)
    {
    }

    #[Route('/.well-known/oauth-protected-resource', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', methods: ['GET'])]
    public function resourceMetadata(): Response
    {
        return new JsonResponse(['resource' => $this->settings->resource(), 'authorization_servers' => [$this->settings->baseUrl], 'scopes_supported' => Repository::SCOPES], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/.well-known/oauth-authorization-server', methods: ['GET'])]
    public function authorizationMetadata(): Response
    {
        return new JsonResponse(['issuer' => $this->settings->baseUrl, 'authorization_endpoint' => $this->settings->baseUrl.'/oauth/authorize', 'token_endpoint' => $this->settings->baseUrl.'/oauth/token', 'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'], 'client_id_metadata_document_supported' => true, 'scopes_supported' => Repository::SCOPES], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/oauth/authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        try {
            $this->checkResource($request->query->get('resource'));
            if ($request->query->get('code_challenge_method') !== 'S256') {
                throw OAuthServerException::invalidRequest('code_challenge_method');
            }
            $factory = new Psr17Factory();
            $bridge = new PsrHttpFactory($factory, $factory, $factory, $factory);
            $server = $this->servers->authorization();
            $auth = $server->validateAuthorizationRequest($bridge->createRequest($request));
            $error = null;
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isTokenValid(new CsrfToken('mcp-consent', (string) $request->request->get('_token', '')))) {
                    return new Response('Session expired. Reload this page.', 403);
                }
                if ($request->request->get('decision') === 'deny') {
                    $auth->setUser(new Owner());
                    $auth->setAuthorizationApproved(false);
                    return $this->fromPsr($server->completeAuthorizationRequest($auth, $factory->createResponse()));
                }
                if ($this->repository->attemptLogin((string) $request->request->get('password', ''))) {
                    $request->getSession()->migrate(true);
                    $this->csrf->removeToken('mcp-consent');
                    $auth->setUser(new Owner());
                    $auth->setAuthorizationApproved(true);
                    return $this->fromPsr($server->completeAuthorizationRequest($auth, $factory->createResponse()));
                }
                $error = 'Incorrect password or too many attempts. Try again later.';
            }
            return new Response($this->twig->render('panel/oauth.html.twig', ['error' => $error, 'scopes' => array_map(static fn ($scope) => $scope->getIdentifier(), $auth->getScopes()), 'token' => $this->csrf->getToken('mcp-consent')->getValue()]), $error ? 401 : 200, ['Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'self'; form-action 'self' https://chatgpt.com; frame-ancestors 'none'; base-uri 'none'", 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);
        } catch (OAuthServerException $e) {
            return $this->fromPsr($e->generateHttpResponse((new Psr17Factory())->createResponse()));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
    }

    #[Route('/oauth/token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        try {
            $this->checkResource($request->request->get('resource'));
            $factory = new Psr17Factory();
            $response = $this->servers->authorization()->respondToAccessTokenRequest((new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request), $factory->createResponse());
            $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $result['resource'] = $this->settings->resource();
            return new JsonResponse($result, headers: ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthServerException $e) {
            return $this->fromPsr($e->generateHttpResponse((new Psr17Factory())->createResponse()));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
    }
    private function checkResource(mixed $resource): void
    {
        if ($resource !== $this->settings->resource()) {
            throw OAuthServerException::invalidRequest('resource');
        }
    }
    private function fromPsr(\Psr\Http\Message\ResponseInterface $response): Response
    {
        $result = (new HttpFoundationFactory())->createResponse($response);
        $result->headers->set('Cache-Control', 'no-store');
        if ($result->getStatusCode() === 302) {
            $result->setStatusCode(303);
        } return $result;
    }
}
