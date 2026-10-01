<?php

declare(strict_types=1);

namespace App\Interface\Http;

use App\Configuration\Application\ConfigurationManager;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment as MarkdownEnvironment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class ConfigController extends AbstractController
{
    public function __construct(private readonly ConfigurationManager $store, private readonly Environment $twig, private readonly CsrfTokenManagerInterface $csrf, private readonly string $docsDirectory)
    {
    }

    #[Route('/', name: 'panel_config', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $error = null;
        $status = 200;
        try {
            $snapshot = $this->store->read();
        } catch (\Throwable) {
            return $this->page('panel/error.html.twig', [], 503);
        }
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            // Keep entered text even when validation or the version check fails.
            foreach (['office' => 'company.yaml', 'cases' => 'knowledge.yaml', 'prices' => 'pricing.yaml'] as $field => $name) {
                $snapshot['files'][$name] = is_string($input[$field] ?? null) ? $input[$field] : '';
            }
            $snapshot['version'] = is_string($input['version'] ?? null) ? $input['version'] : '';
            if (!$this->validToken($input)) {
                $error = 'Your form session expired. Copy your edits and reload.';
                $status = 403;
            } else {
                try {
                    $this->store->save($snapshot['files'], $snapshot['version']);
                    return $this->redirectToRoute('panel_config', ['saved' => 1], 303);
                } catch (\InvalidArgumentException|\DomainException $e) {
                    $error = $e->getMessage();
                    $status = $e instanceof \DomainException ? 409 : 422;
                } catch (\Throwable) {
                    $error = 'Saving failed. Keep your edits and contact the administrator.';
                    $status = 503;
                }
            }
        }
        return $this->page('panel/edit.html.twig', ['snapshot' => $snapshot, 'error' => $error, 'saved' => $request->isMethod('GET') && $request->query->get('saved') === '1', 'token' => $this->csrf->getToken('config')->getValue()], $status);
    }

    #[Route('/przywroc', name: 'panel_restore', methods: ['GET', 'POST'])]
    public function restore(Request $request): Response
    {
        try {
            $snapshot = $this->store->read();
        } catch (\Throwable) {
            return $this->page('panel/error.html.twig', [], 503);
        }
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $snapshot['version'] = is_string($input['version'] ?? null) ? $input['version'] : '';
            if (!$this->validToken($input)) {
                $error = 'Your form session expired. Reload this page.';
                $status = 403;
            } else {
                try {
                    $this->store->restore(is_string($input['version'] ?? null) ? $input['version'] : '');
                    return $this->redirectToRoute('panel_config', ['saved' => 1], 303);
                } catch (\InvalidArgumentException|\DomainException $e) {
                    $error = $e->getMessage();
                    $status = 409;
                } catch (\Throwable) {
                    $error = 'Restoration failed. Contact the administrator.';
                    $status = 503;
                }
            }
        }
        return $this->page('panel/restore.html.twig', ['snapshot' => $snapshot, 'error' => $error, 'token' => $this->csrf->getToken('config')->getValue()], $status);
    }

    #[Route('/przewodnik', name: 'panel_guide', methods: ['GET'])]
    #[Route('/dokumentacja', name: 'panel_docs', methods: ['GET'])]
    public function documentation(Request $request): Response
    {
        $guide = $request->attributes->get('_route') === 'panel_guide';
        $markdown = file_get_contents($this->docsDirectory.'/'.$request->getLocale().'/'.($guide ? 'przewodnik' : 'dokumentacja').'.md');
        $environment = new MarkdownEnvironment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'heading_permalink' => ['symbol' => '', 'id_prefix' => '', 'fragment_prefix' => '']]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $converter = new MarkdownConverter($environment);
        return $this->page('panel/document.html.twig', ['content' => (string) $converter->convert($markdown), 'title' => $guide ? 'Owner guide' : 'Reference']);
    }

    private function validToken(array $input): bool
    {
        return is_string($input['_token'] ?? null) && $this->csrf->isTokenValid(new CsrfToken('config', $input['_token']));
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        return new Response($this->twig->render($template, $data), $status, [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
        ]);
    }
}
