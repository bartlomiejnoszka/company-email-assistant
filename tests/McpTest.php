<?php

declare(strict_types=1);

namespace App\Tests;

use App\Interface\Console\ConfigureMcpCommand;
use App\Identity\Infrastructure\OAuth\Repository;
use App\Identity\Infrastructure\OAuth\Settings;
use App\Configuration\Infrastructure\ConfigSnapshotStore;
use App\Configuration\Domain\EditingSettings;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};

final class McpTest extends TestCase
{
    private string $dir;
    private KernelBrowser $client;
    private Settings $settings;
    private array $oldEnv;
    private mixed $exceptionHandler;
    private string $accessToken = '';
    private ?string $sessionId = null;
    protected function setUp(): void
    {
        $this->exceptionHandler = set_exception_handler(static function (\Throwable $e): void {
        });
        restore_exception_handler();
        (new Dotenv())->bootEnv(__DIR__.'/../.env');
        $this->dir = sys_get_temp_dir().'/notariat-mcp-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        mkdir($this->dir.'/office', 0700);
        foreach (ConfigSnapshotStore::FILES as $name) {
            copy(__DIR__.'/../examples/notary/'.$name, $this->dir.'/office/'.$name);
        }
        $this->settings = new Settings($this->dir.'/auth', 'https://assistant.example.test');
        (new CommandTester(new ConfigureMcpCommand($this->settings)))->execute(['--redirect-uri' => 'https://chatgpt.com/connector_platform/oauth_redirect']);
        $config = $this->settings->read();
        $config['owner_password_hash'] = password_hash('test-owner-password', PASSWORD_DEFAULT);
        $config['client_secret_hash'] = null;
        file_put_contents($this->dir.'/auth/settings.json', json_encode($config));
        $this->oldEnv = [];
        foreach (['COMPANY_CONFIG_DIR' => $this->dir.'/office', 'MCP_AUTH_DIR' => $this->dir.'/auth', 'MCP_BASE_URL' => $this->settings->baseUrl, 'MCP_HOST' => 'assistant.example.test'] as $key => $value) {
            $this->oldEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->client = new KernelBrowser(new Kernel('test', false));
    }
    protected function tearDown(): void
    {
        $this->client->getKernel()->shutdown();
        while (true) {
            $handler = set_exception_handler(static function (\Throwable $e): void {
            });
            restore_exception_handler();
            if ($handler === $this->exceptionHandler) {
                break;
            }
            restore_exception_handler();
        }
        foreach ($this->oldEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
    private function authorize(string $scope = 'configuration:read configuration:write', string $verifier = ''): array
    {
        $verifier = $verifier ?: str_repeat('a', 64);
        $query = ['response_type' => 'code', 'client_id' => 'company-assistant', 'redirect_uri' => 'https://chatgpt.com/connector_platform/oauth_redirect', 'scope' => $scope, 'state' => 'test-state', 'resource' => $this->settings->resource(), 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        $crawler = $this->client->request('GET', '/oauth/authorize?'.http_build_query($query));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        self::assertStringContainsString("form-action 'self' https://chatgpt.com;", $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $this->client->submit($crawler->selectButton('Authorize ChatGPT')->form(['password' => 'test-owner-password']));
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        parse_str(parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);
        self::assertSame('test-state', $params['state']);
        return ['grant_type' => 'authorization_code', 'client_id' => 'company-assistant', 'redirect_uri' => $query['redirect_uri'], 'code' => $params['code'], 'code_verifier' => $verifier, 'resource' => $this->settings->resource()];
    }
    private function login(string $scope = 'configuration:read configuration:write'): array
    {
        $params = $this->authorize($scope);
        $this->client->request('POST', '/oauth/token', $params);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $result = json_decode($this->client->getResponse()->getContent(), true);
        $this->accessToken = $result['access_token'];
        return $result;
    }
    private function rpc(string $method, array $params = [], int $id = 1): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if ($this->sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->sessionId;
        }
        $this->client->request('POST', '/mcp', [], [], $headers, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: new \stdClass()]));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->sessionId = $response->headers->get('Mcp-Session-Id') ?? $this->sessionId;
        $text = $response->getContent();
        if (str_starts_with($text, 'event:') || str_starts_with($text, 'data:')) {
            preg_match('/^data: (.+)$/m', $text, $match);
            $text = $match[1];
        }
        $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $result, $text);
        return $result['result'];
    }
    private function initialize(): array
    {
        return $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
    }
    private function call(string $name, array $arguments = []): array
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments ?: new \stdClass()]);
        self::assertFalse($result['isError'] ?? false, json_encode($result));
        return $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }
    public function testDiscoveryRequiresOAuthAndPanelRequiresLogin(): void
    {
        $this->client->request('GET', '/');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('https://chatgpt.com', $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('oauth-protected-resource', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $metadata = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertTrue($metadata['client_id_metadata_document_supported']);
    }
    public function testFullOAuthMcpEditingWorkflowPreservesBytesAndHistory(): void
    {
        $this->login();
        self::assertStringContainsString('confirmation', $this->initialize()['instructions']);
        $tools = $this->rpc('tools/list')['tools'];
        self::assertCount(7, $tools);
        foreach ($tools as $tool) {
            self::assertNotEmpty($tool['_meta']['securitySchemes']);
            if ($tool['name'] === 'save_configuration') {
                self::assertFalse($tool['annotations']['readOnlyHint']);
                self::assertTrue($tool['annotations']['destructiveHint']);
                self::assertFalse($tool['inputSchema']['properties']['files']['additionalProperties']);
            }
        }
        $before = $this->call('get_configuration');
        self::assertSame(EditingSettings::OPTIONS, $before['guidance']['preferences']['style']['values']);
        self::assertSame(EditingSettings::DEFAULTS, $before['guidance']['preferences']['style']['defaults']);
        self::assertNotEmpty($before['guidance']['configuration_reference']);
        $files = $before['files'];
        $files['company.yaml'] = "\n".$files['company.yaml']."\n# Zażółć gęślą jaźń\n";
        $args = ['files' => $files, 'expected_version' => $before['version']];
        $validation = $this->call('validate_configuration', $args);
        self::assertTrue($validation['valid']);
        self::assertArrayHasKey('company.yaml', $validation['diff']);
        self::assertSame($before['files'], (new ConfigSnapshotStore($this->dir.'/office'))->read()['files']);
        self::assertFalse($this->call('save_configuration', $args + ['confirmed' => false])['ok']);
        $saved = $this->call('save_configuration', $args + ['confirmed' => true]);
        self::assertTrue($saved['ok']);
        self::assertSame($files, (new ConfigSnapshotStore($this->dir.'/office'))->read()['files']);
        self::assertFalse($this->call('save_configuration', $args + ['confirmed' => true])['ok']);
        self::assertCount(2, $this->call('list_configuration_versions')['versions']);
        self::assertTrue($this->call('restore_previous_configuration', ['expected_version' => $saved['version'], 'confirmed' => true])['ok']);
        self::assertSame($before['files'], (new ConfigSnapshotStore($this->dir.'/office'))->read()['files']);
        self::assertSame(EditingSettings::OPTIONS, $this->call('get_configuration_guidance')['guidance']['preferences']['style']['values']);
        self::assertCount(1, $this->rpc('resources/list')['resources']);
        self::assertNotEmpty($this->rpc('resources/read', ['uri' => 'assistant://configuration/guidance'])['contents'][0]['text']);
    }
    public function testInvalidPackageDoesNotActivateAndReadOnlyScopeCannotSave(): void
    {
        $this->login('configuration:read');
        $this->initialize();
        $before = $this->call('get_configuration');
        $files = $before['files'];
        $files['knowledge.yaml'] = 'bad: [';
        self::assertFalse($this->call('validate_configuration', ['files' => $files, 'expected_version' => $before['version']])['ok']);
        $result = $this->rpc('tools/call', ['name' => 'save_configuration', 'arguments' => ['files' => $before['files'], 'expected_version' => $before['version'], 'confirmed' => true]]);
        self::assertTrue($result['isError']);
        self::assertSame($before['files'], (new ConfigSnapshotStore($this->dir.'/office'))->read()['files']);
    }
    public function testAuthenticatedReadOnlyPreviewWithProposedPackageDoesNotActivate(): void
    {
        require_once __DIR__.'/Support.php';
        $this->client->disableReboot();
        $this->login('configuration:read');
        $this->initialize();
        $ai = new FakeAi();
        $ai->matchResult['topic_ids'] = ['kontakt'];
        $ai->proposal = Support::proposal(['blocks' => ['fact:sprawa.kontakt.fact'], 'used_fact_ids' => ['sprawa.kontakt.fact']]);
        $this->client->getContainer()->set('preview.ai', $ai);
        $before = $this->call('get_configuration');
        $tool = array_values(array_filter($this->rpc('tools/list')['tools'], static fn ($tool) => $tool['name'] === 'test_mail_response'))[0];
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertTrue($tool['annotations']['openWorldHint']);
        self::assertSame(['body', 'expected_version'], $tool['inputSchema']['required']);
        $args = ['body' => 'Jakie dokumenty przygotować?', 'expected_version' => $before['version']];
        $preview = $this->call('test_mail_response', $args);
        self::assertTrue($preview['ok']);
        self::assertSame('reply', $preview['outcome']);
        self::assertSame('active', $preview['configuration_source']);
        $files = $before['files'];
        $files['company.yaml'] = str_replace('length: standard', 'length: short', $files['company.yaml']);
        $preview = $this->call('test_mail_response', $args + ['files' => $files]);
        self::assertTrue($preview['ok']);
        self::assertSame('proposed', $preview['configuration_source']);
        self::assertSame($before['version'], $this->call('get_configuration')['version']);
        self::assertSame($before['files'], $this->call('get_configuration')['files']);
        self::assertSame('version_conflict', $this->call('test_mail_response', array_replace($args, ['expected_version' => 'stale']))['error']);
    }

    public function testPkceWrongResourceAndAuthorizationCodeReplayAreRejected(): void
    {
        $params = $this->authorize();
        $this->client->request('POST', '/oauth/token', array_replace($params, ['resource' => 'https://evil.invalid/mcp']));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/oauth/token', array_replace($params, ['code_verifier' => str_repeat('b', 64)]));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/oauth/token', $params);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/oauth/token', $params);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
    public function testRefreshRotationRevocationAndInvalidBearer(): void
    {
        $tokens = $this->login();
        $params = ['grant_type' => 'refresh_token', 'client_id' => 'company-assistant', 'refresh_token' => $tokens['refresh_token'], 'resource' => $this->settings->resource()];
        $this->client->request('POST', '/oauth/token', $params);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $new = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/oauth/token', $params);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        (new CommandTester(new \App\Interface\Console\RevokeMcpCommand($this->settings)))->execute([]);
        foreach ([$new['access_token'], 'invalid.token.value'] as $token) {
            $this->client->request('POST', '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], '{}');
            self::assertSame(401, $this->client->getResponse()->getStatusCode());
        }
    }
    public function testSignedTokensWithWrongAudienceIssuerOrExpiryAreRejected(): void
    {
        $repo = new Repository($this->settings, new MockHttpClient());
        foreach ([['https://wrong.invalid', $this->settings->resource(), '+1 hour'], [$this->settings->baseUrl, 'https://wrong.invalid/mcp', '+1 hour'], [$this->settings->baseUrl, $this->settings->resource(), '-1 hour']] as [$issuer, $audience, $expiry]) {
            $token = new \App\Identity\Infrastructure\OAuth\Entity\AccessToken($issuer, $audience);
            $token->setIdentifier(bin2hex(random_bytes(16)));
            $token->setClient(new \App\Identity\Infrastructure\OAuth\Entity\Client('company-assistant', ['https://chatgpt.com/connector_platform/oauth_redirect']));
            $token->setUserIdentifier('owner');
            $token->setExpiryDateTime(new \DateTimeImmutable($expiry));
            $token->addScope(new \App\Identity\Infrastructure\OAuth\Entity\Scope('configuration:read'));
            $token->setPrivateKey(new \League\OAuth2\Server\CryptKey($this->dir.'/auth/private.key'));
            $repo->persistNewAccessToken($token);
            $this->client->request('POST', '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token->toString(), 'CONTENT_TYPE' => 'application/json'], '{}');
            self::assertSame(401, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testConsentCsrfAndPasswordThrottle(): void
    {
        $this->client->request('POST', '/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => 'company-assistant', 'redirect_uri' => 'https://chatgpt.com/connector_platform/oauth_redirect', 'scope' => 'configuration:read', 'resource' => $this->settings->resource(), 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']), ['password' => 'test-owner-password', '_token' => 'bad']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $repo = new Repository($this->settings, new MockHttpClient());
        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($repo->attemptLogin('bad'));
        }
        self::assertFalse($repo->attemptLogin('test-owner-password'));
    }
    public function testCimdRestrictsMetadataOriginAndExactPublishedRedirects(): void
    {
        $id = 'https://chatgpt.com/oauth/client.json';
        $http = new MockHttpClient(new MockResponse(json_encode(['client_id' => $id, 'redirect_uris' => ['https://chatgpt.com/connector_platform/oauth_redirect'], 'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt']])));
        $repo = new Repository($this->settings, $http);
        self::assertNull($repo->getClientEntity('https://127.0.0.1/oauth/client.json'));
        self::assertNull($repo->getClientEntity('https://chatgpt.com.evil.invalid/oauth/client.json'));
        self::assertSame(['https://chatgpt.com/connector_platform/oauth_redirect'], $repo->getClientEntity($id)->getRedirectUri());
        self::assertSame(1, $http->getRequestsCount());
        $bad = new Repository($this->settings, new MockHttpClient(new MockResponse(json_encode(['client_id' => $id, 'redirect_uris' => ['https://evil.invalid/callback']]))));
        self::assertNull($bad->getClientEntity($id));
    }
    public function testPreferenceExamplesAndDependencyValidation(): void
    {
        $store = new ConfigSnapshotStore($this->dir.'/office');
        $files = $store->read()['files'];
        $files['company.yaml'] = str_replace('length: standard', 'length: short', $files['company.yaml']);
        $files['company.yaml'] = str_replace('tone: warm', 'tone: empathetic', $files['company.yaml']);
        $store->validate($files);
        self::assertSame(['mode' => 'mixed', 'style' => array_replace(EditingSettings::DEFAULTS, ['formality' => 'formal'])], EditingSettings::effective(['mode' => 'mixed'], ['style' => ['formality' => 'formal']]));
        $files['company.yaml'] = str_replace('dopasowanie: ai', 'dopasowanie: keywords', $files['company.yaml']);
        $files['knowledge.yaml'] = "dokumenty:\n  opis: 'Pytania o dokumenty'\n  zapytaj: 'Jaka czynność?'\n";
        $this->expectException(\InvalidArgumentException::class);
        $store->validate($files);
    }
}
