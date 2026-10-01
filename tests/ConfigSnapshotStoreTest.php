<?php

declare(strict_types=1);

namespace App\Tests;

use App\Configuration\Infrastructure\ConfigLoader;
use App\Configuration\Infrastructure\ConfigSnapshotStore;
use PHPUnit\Framework\TestCase;

final class ConfigSnapshotStoreTest extends TestCase
{
    private string $dir;
    private ConfigSnapshotStore $store;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/office-panel-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        foreach (ConfigSnapshotStore::FILES as $name) {
            copy(__DIR__.'/../examples/notary/'.$name, $this->dir.'/'.$name);
        }
        $this->store = new ConfigSnapshotStore($this->dir);
    }
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
    public function testSavePreservesExactTextAndRestoresAllFilesWithoutChangingOldSnapshot(): void
    {
        $before = $this->store->read();
        $files = $before['files'];
        $files['company.yaml'] .= "\n# Zażółć gęślą jaźń — komentarz właściciela\n";
        $id = $this->store->save($files, $before['version']);
        self::assertSame($files, $this->store->read()['files']);
        self::assertTrue($this->store->read()['previous']);
        $resolved = ConfigSnapshotStore::resolveDirectory($this->dir);
        $hash = (new ConfigLoader($this->dir))->load()->hash;
        $restored = $this->store->restore($id);
        self::assertNotSame($id, $restored);
        self::assertSame($before['files'], $this->store->read()['files']);
        self::assertSame($files['company.yaml'], file_get_contents($resolved.'/company.yaml'));
        self::assertSame($hash, (new ConfigLoader($this->dir))->load()->hash);
        $this->store->restore($restored);
        self::assertSame($files, $this->store->read()['files']);
    }
    public function testInvalidYamlDoesNotActivateAnyChanges(): void
    {
        $before = $this->store->read();
        $files = $before['files'];
        $files['knowledge.yaml'] = "bad: [\n";
        try {
            $this->store->save($files, $before['version']);
            self::fail('Expected validation failure');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('knowledge.yaml', $e->getMessage());
            self::assertStringContainsString('line', $e->getMessage());
        }
        self::assertSame($before, $this->store->read());
        self::assertFileDoesNotExist($this->dir.'/CURRENT');
    }
    public function testDanglingPriceReferenceIsRejected(): void
    {
        $before = $this->store->read();
        $files = $before['files'];
        $files['pricing.yaml'] = "nieistniejaca:\n  cena:\n    opis: 'Wycena indywidualna'\n    warunki: 'Po ocenie sprawy'\n    od: '2026-01-01'\n    do: '2026-12-31'\n";
        try {
            $this->store->save($files, $before['version']);
            self::fail('Expected validation failure');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('pricing.yaml', $e->getMessage());
        }
        self::assertSame($before, $this->store->read());
    }
    public function testConcurrentOldFormCannotOverwriteOrRestoreNewVersion(): void
    {
        $first = $this->store->read();
        $other = new ConfigSnapshotStore($this->dir);
        $this->store->save($first['files'], $first['version']);
        foreach (['save', 'restore'] as $action) {
            try {
                $action === 'save' ? $other->save($first['files'], $first['version']) : $other->restore($first['version']);
                self::fail('Expected stale version rejection');
            } catch (\DomainException $e) {
                self::assertStringContainsString('changed', $e->getMessage());
            }
        }
    }
    public function testFailedActivationLeavesCurrentVersionUsable(): void
    {
        $before = $this->store->read();
        // A directory at the pointer path forces the final rename to fail after validation and writing.
        mkdir($this->dir.'/CURRENT');
        set_error_handler(static fn () => true);
        try {
            try {
                $this->store->save($before['files'], $before['version']);
                self::fail('Expected write failure');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('activate', $e->getMessage());
            }
        } finally {
            restore_error_handler();
        }
        self::assertSame($before, $this->store->read());
        self::assertSame([], glob($this->dir.'/.CURRENT-*'));
    }
    public function testOversizedAndUnknownFilesAreRejected(): void
    {
        $snapshot = $this->store->read();
        $files = $snapshot['files'];
        $files['pricing.yaml'] = str_repeat('x', ConfigSnapshotStore::MAX_FILE_BYTES + 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->store->save($files, $snapshot['version']);
    }
}
