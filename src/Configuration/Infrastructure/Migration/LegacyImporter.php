<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure\Migration;

use App\Configuration\Infrastructure\{ConfigLoader, ConfigSnapshotStore};
use Symfony\Component\Yaml\Yaml;

/** Imports alongside the source. Never changes CURRENT, the processing database or source files. */
final class LegacyImporter
{
    public function import(string $source, string $destination): array
    {
        if (!is_dir($source)) {
            throw new \InvalidArgumentException('Source directory does not exist.');
        }
        if (file_exists($destination)) {
            throw new \InvalidArgumentException('Destination must not exist.');
        }
        $config = (new LegacyConfigLoader($source))->load();
        $reply = $config->rules;
        unset($reply['topics'], $reply['clarifications']);
        $documents = [
            'company.yaml' => ['schema_version' => 1, 'company' => $config->company, 'reply' => $reply],
            'knowledge.yaml' => ['topics' => $config->rules['topics'], 'facts' => $config->knowledge, 'clarifications' => $config->rules['clarifications']],
            'pricing.yaml' => ['prices' => $config->pricing],
        ];
        $parent = dirname($destination);
        if (!is_dir($parent)) {
            throw new \InvalidArgumentException('Destination parent must exist.');
        }
        $sourcePath = realpath($source);
        $parentPath = realpath($parent);
        if ($sourcePath === false || $parentPath === false) {
            throw new \InvalidArgumentException('Source or destination parent is unavailable.');
        }
        if ($parentPath === $sourcePath || str_starts_with($parentPath, $sourcePath.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Destination must be outside the source directory.');
        }
        $staging = $parent.'/.import-'.bin2hex(random_bytes(16));
        if (!mkdir($staging, 0700)) {
            throw new \RuntimeException('Cannot create import directory.');
        }
        try {
            foreach ($documents as $name => $data) {
                file_put_contents($staging.'/'.$name, Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            }
            $migrated = (new ConfigLoader($staging))->load();
            if ($config->hash !== $migrated->hash) {
                throw new \RuntimeException('Import changed normalized content.');
            }
            $archive = $staging.'/legacy-archive';
            mkdir($archive, 0700);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                $relative = substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
                // Only approved configuration and its history; never copy locks or unrelated files.
                if ($item->isLink()) {
                    throw new \InvalidArgumentException('Source archive must not contain symlinks.');
                }
                if ($item->isDir()) {
                    mkdir($archive.'/'.$relative, 0700, true);
                    continue;
                }
                if (!in_array($item->getFilename(), ['kancelaria.yaml','sprawy.yaml','cennik.yaml','CURRENT','metadata.json'], true)) {
                    continue;
                }
                if (!copy($item->getPathname(), $archive.'/'.$relative)) {
                    throw new \RuntimeException('Archive copy failed.');
                }
                chmod($archive.'/'.$relative, 0400);
            }
            $report = ['schema_version' => 1, 'normalized_hash' => $migrated->hash, 'content_preserved' => true, 'processing_state_changed' => false, 'legacy_history' => 'legacy-archive'];
            file_put_contents($staging.'/migration-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            // Initialize generic history; old-format history remains outside the restore chain.
            $store = new ConfigSnapshotStore($staging);
            $snapshot = $store->read();
            $store->save($snapshot['files'], $snapshot['version']);
            if ((new LegacyConfigLoader($source))->load()->hash !== $config->hash) {
                throw new \RuntimeException('Source configuration changed during import. Retry from a consistent copy.');
            }
            if (file_exists($destination) || !rename($staging, $destination)) {
                throw new \RuntimeException('Cannot publish imported configuration.');
            }
            return $report;
        } catch (\Throwable $e) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($staging);
            throw $e;
        }
    }
}
