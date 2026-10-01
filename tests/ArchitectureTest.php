<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function testDomainHasNoFrameworkProviderOrPersistenceDependency(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_contains($file->getPathname(), '/Domain/') || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach (['use Symfony', 'use Doctrine', 'use Webklex', 'use League', 'Infrastructure\\'] as $dependency) {
                self::assertStringNotContainsString($dependency, $source, $file->getPathname());
            }
        }
    }
    public function testApplicationServicesDependOnPortsRatherThanAdapters(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_contains($file->getPathname(), '/Application/') || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach (['Infrastructure\\', 'use Symfony', 'use Doctrine', 'use Webklex'] as $dependency) {
                self::assertStringNotContainsString($dependency, $source, $file->getPathname());
            }
        }
    }

}
