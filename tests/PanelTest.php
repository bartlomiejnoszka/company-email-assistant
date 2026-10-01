<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\Configuration\Infrastructure\ConfigSnapshotStore;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Dotenv\Dotenv;

final class PanelTest extends TestCase
{
    private string $dir;
    private KernelBrowser $client;
    private array $previousEnvironment;
    private mixed $exceptionHandler;
    protected function setUp(): void
    {
        $this->exceptionHandler = set_exception_handler(static function (\Throwable $e): void {
        });
        restore_exception_handler();
        (new Dotenv())->bootEnv(__DIR__.'/../.env');
        $this->previousEnvironment = [];
        foreach (['COMPANY_CONFIG_DIR','UI_LOCALE','MCP_AUTH_DIR'] as $key) {
            $this->previousEnvironment[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['UI_LOCALE'] = $_SERVER['UI_LOCALE'] = 'pl';
        $this->dir = sys_get_temp_dir().'/panel-http-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        foreach (ConfigSnapshotStore::FILES as $name) {
            copy(__DIR__.'/../examples/notary/'.$name, $this->dir.'/'.$name);
        }
        $_ENV['COMPANY_CONFIG_DIR'] = $_SERVER['COMPANY_CONFIG_DIR'] = $this->dir;
        $_ENV['MCP_AUTH_DIR'] = $_SERVER['MCP_AUTH_DIR'] = $this->dir.'/identity';
        (new \Symfony\Component\Console\Tester\CommandTester(new \App\Interface\Console\ConfigureMcpCommand(new \App\Identity\Infrastructure\OAuth\Settings($this->dir.'/identity', 'http://localhost'))))->execute([]);
        $identity = json_decode(file_get_contents($this->dir.'/identity/settings.json'), true);
        $identity['owner_password_hash'] = password_hash('test-owner-password', PASSWORD_DEFAULT);
        file_put_contents($this->dir.'/identity/settings.json', json_encode($identity));
        $this->client = new KernelBrowser(new Kernel('test', false));
        $this->client->getKernel()->boot();
        $this->client->loginUser(new \App\Identity\Infrastructure\PanelOwner($identity['owner_password_hash']), 'owner');
    }
    protected function tearDown(): void
    {
        $this->client->getKernel()->shutdown();
        // Symfony installs a process-wide exception handler when the HTTP kernel boots.
        while (true) {
            $handler = set_exception_handler(static function (\Throwable $e): void {
            });
            restore_exception_handler();
            if ($handler === $this->exceptionHandler) {
                break;
            }
            restore_exception_handler();
        }
        foreach ($this->previousEnvironment as $key => $value) {
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
    public function testThreeFieldsSaveExactYamlAndRestoreRequiresConfirmation(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $crawler->filter('textarea'));
        self::assertStringContainsString("frame-ancestors 'none'", $this->client->getResponse()->headers->get('Content-Security-Policy'));
        $old = file_get_contents($this->dir.'/company.yaml');
        $text = $old."\n# komentarz właściciela\n";
        $this->client->submit($crawler->selectButton('Zapisz konfigurację')->form(['office' => $text]));
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $store = new ConfigSnapshotStore($this->dir);
        self::assertSame($text, $store->read()['files']['company.yaml']);
        $before = $store->read();
        $crawler = $this->client->request('GET', '/przywroc');
        self::assertSame($before, $store->read());
        $this->client->submit($crawler->selectButton('Tak, przywróć poprzednią wersję')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame($old, $store->read()['files']['company.yaml']);
    }
    public function testLeadingBlankLineSurvivesAnUnchangedFormSubmission(): void
    {
        $path = $this->dir.'/company.yaml';
        $text = "\n".file_get_contents($path);
        file_put_contents($path, $text);
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Zapisz konfigurację')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame($text, (new ConfigSnapshotStore($this->dir))->read()['files']['company.yaml']);
    }
    public function testInvalidYamlIsPreservedAndEscaped(): void
    {
        $crawler = $this->client->request('GET', '/');
        $input = "</textarea><script>alert('x')</script>\nbad: [";
        $this->client->submit($crawler->selectButton('Zapisz konfigurację')->form(['cases' => $input]));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame($input, $this->client->getCrawler()->filter('#cases')->text('', false));
        self::assertCount(0, $this->client->getCrawler()->filter('script'));
        self::assertFileDoesNotExist($this->dir.'/CURRENT');
    }
    public function testCsrfIsRequiredForBothMutations(): void
    {
        foreach (['/', '/przywroc'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'invalid']);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
            self::assertFileDoesNotExist($this->dir.'/CURRENT');
        }
    }
    public function testStaleFormCannotOverwriteAndKeepsUserText(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Zapisz konfigurację')->form();
        $store = new ConfigSnapshotStore($this->dir);
        $snapshot = $store->read();
        $store->save($snapshot['files'], $snapshot['version']);
        $form['prices'] = "prices: {}\n# Moja zmiana";
        $this->client->submit($form);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Moja zmiana', $this->client->getCrawler()->filter('#prices')->text());
    }
    public function testDocumentationAndGuideAreAuthenticatedAndAnchorsExist(): void
    {
        foreach (['/przewodnik', '/dokumentacja'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            foreach (['company', 'knowledge', 'pricing'] as $anchor) {
                self::assertCount(1, $crawler->filter('#'.$anchor));
            }
            self::assertCount(0, $crawler->filter('script'));
            self::assertStringNotContainsString('IMAP_PASSWORD', $this->client->getResponse()->getContent());
        }
    }
    private function signOut(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Wyloguj')->form());
    }
    public function testAnonymousPanelAndDocumentsRequireLogin(): void
    {
        $this->signOut();
        foreach (['/', '/przewodnik', '/dokumentacja', '/przywroc'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertStringEndsWith('/login', $this->client->getResponse()->headers->get('Location'));
        }
    }
    public function testLoginCsrfFailuresAndLogout(): void
    {
        $this->signOut();
        $this->client->request('POST', '/login', ['password' => 'test-owner-password', '_csrf_token' => 'invalid']);
        $this->client->request('GET', '/');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Zaloguj')->form(['password' => 'wrong']));
        $this->client->request('GET', '/');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Zaloguj')->form(['password' => 'test-owner-password']));
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/logout', ['_csrf_token' => 'bad']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->signOut();
        $this->client->request('GET', '/');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }
    public function testOwnerSessionCannotAuthenticateMcp(): void
    {
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
    public function testLoginThrottleIncludesValidPasswordAfterLimit(): void
    {
        $this->signOut();
        for ($i = 0; $i < 10; ++$i) {
            $crawler = $this->client->request('GET', '/login');
            $this->client->submit($crawler->selectButton('Zaloguj')->form(['password' => 'wrong']));
        }
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Zaloguj')->form(['password' => 'test-owner-password']));
        $this->client->request('GET', '/');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

}
