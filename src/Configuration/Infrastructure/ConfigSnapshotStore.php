<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure;

use App\Configuration\Domain\CompanyConfig;

/** Immutable YAML snapshots: CLI readers resolve CURRENT once; only panel writers take a lock. */
final class ConfigSnapshotStore implements \App\Configuration\Application\Port\ConfigurationStorage
{
    public const FILES = \App\Configuration\Domain\ConfigurationFiles::NAMES;
    public const MAX_FILE_BYTES = 262144;
    public function __construct(private readonly string $directory)
    {
    }

    public static function resolveDirectory(string $root): string
    {
        $pointer = $root.'/CURRENT';
        if (!is_file($pointer)) {
            return $root;
        }
        $id = trim((string) file_get_contents($pointer));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !is_dir($root.'/versions/'.$id)) {
            throw new \RuntimeException('Cannot read the active configuration version. Contact the administrator.');
        }
        return $root.'/versions/'.$id;
    }

    /** @return array{version: string, files: array<string,string>, previous: bool} */
    public function read(): array
    {
        $path = self::resolveDirectory($this->directory);
        $files = $this->readFiles($path);
        $metadata = $this->metadata($path);
        return ['version' => $path === $this->directory ? $this->initialVersion($files) : basename($path), 'files' => $files, 'previous' => isset($metadata['previous'])];
    }

    /** Validate a candidate in a private temporary directory without activation or history changes. */
    public function validate(array $files): void
    {
        $this->loadFiles($files);
    }

    /** Load exactly the supplied snapshot, never resolve a second CURRENT during a preview. */
    public function loadFiles(array $files): CompanyConfig
    {
        $this->checkFiles($files);
        $path = sys_get_temp_dir().'/assistant-validation-'.bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot create validation directory.');
        }
        try {
            foreach (self::FILES as $name) {
                $this->put($path.'/'.$name, $files[$name]);
            }
            return (new ConfigLoader($path))->load();
        } finally {
            foreach (glob($path.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($path);
        }
    }

    /** Only traverse the active ancestry, never partially written or abandoned candidates. */
    public function history(): array
    {
        $current = $this->read();
        if (str_starts_with($current['version'], 'initial-')) {
            return [['version' => $current['version'], 'active' => true, 'action' => 'initial']];
        }
        $result = [];
        $seen = [];
        $id = $current['version'];
        while ($id !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/D', $id) || isset($seen[$id])) {
                throw new \RuntimeException('Invalid version history.');
            }
            $seen[$id] = true;
            $metadata = $this->metadata($this->directory.'/versions/'.$id);
            $result[] = ['version' => $id, 'active' => $id === $current['version'], ...$metadata];
            $id = $metadata['previous'] ?? '';
        }
        return $result;
    }

    public function save(array $files, string $expectedVersion): string
    {
        return $this->write($files, $expectedVersion, false);
    }

    public function restore(string $expectedVersion): string
    {
        return $this->write([], $expectedVersion, true);
    }

    private function write(array $files, string $expectedVersion, bool $restore): string
    {
        $lock = fopen($this->directory.'/.write.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock configuration for writing.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock configuration for writing.');
            }
            $current = $this->read();
            if (!hash_equals($current['version'], $expectedVersion)) {
                throw new \DomainException('Configuration changed since this form was opened. Copy your edits and reload the active version.');
            }
            if ($restore) {
                $metadata = $this->metadata(self::resolveDirectory($this->directory));
                $previous = $metadata['previous'] ?? '';
                if (!preg_match('/^[a-f0-9]{32}$/D', $previous)) {
                    throw new \DomainException('No previous version is available.');
                }
                $files = $this->readFiles($this->directory.'/versions/'.$previous);
            }
            $this->checkFiles($files);
            if (!is_dir($this->directory.'/versions') && !mkdir($this->directory.'/versions', 0700)) {
                throw new \RuntimeException('Cannot create the version directory.');
            }
            $id = bin2hex(random_bytes(16));
            $path = $this->directory.'/versions/'.$id;
            if (!mkdir($path, 0700)) {
                throw new \RuntimeException('Cannot create a version.');
            }
            $activated = false;
            try {
                foreach (self::FILES as $name) {
                    $this->put($path.'/'.$name, $files[$name]);
                }
                // Validate the whole immutable candidate before publishing any part of it.
                (new ConfigLoader($path))->load();
                $previous = $current['version'];
                if (str_starts_with($previous, 'initial-')) {
                    $previous = $this->archiveInitial($current['files']);
                }
                $this->put($path.'/metadata.json', json_encode(['created_at' => gmdate('c'), 'previous' => $previous, 'action' => $restore ? 'restore' : 'save'], JSON_THROW_ON_ERROR));
                $this->put($this->directory.'/.CURRENT-'.$id, $id."\n");
                if (!rename($this->directory.'/.CURRENT-'.$id, $this->directory.'/CURRENT')) {
                    throw new \RuntimeException('Cannot activate configuration.');
                }
                $activated = true;
                return $id;
            } finally {
                if (!$activated) {
                    foreach (glob($path.'/*') ?: [] as $file) {
                        unlink($file);
                    }
                    rmdir($path);
                    if (is_file($this->directory.'/.CURRENT-'.$id)) {
                        unlink($this->directory.'/.CURRENT-'.$id);
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function archiveInitial(array $files): string
    {
        $id = bin2hex(random_bytes(16));
        $path = $this->directory.'/versions/'.$id;
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Cannot archive the previous configuration.');
        }
        foreach (self::FILES as $name) {
            $this->put($path.'/'.$name, $files[$name]);
        }
        $this->put($path.'/metadata.json', json_encode(['created_at' => gmdate('c'), 'action' => 'initial'], JSON_THROW_ON_ERROR));
        return $id;
    }

    private function checkFiles(array $files): void
    {
        $keys = array_keys($files);
        sort($keys);
        $expected = self::FILES;
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('The package must contain exactly three YAML files.');
        }
        foreach ($files as $name => $text) {
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || strlen($text) > self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException($name.': expected UTF-8 text up to 256 KB without NUL characters.');
            }
        }
    }

    private function readFiles(string $path): array
    {
        $files = [];
        foreach (self::FILES as $name) {
            $text = @file_get_contents($path.'/'.$name);
            if ($text === false) {
                throw new \RuntimeException('Cannot read '.$name.'. Contact the administrator.');
            }
            $files[$name] = $text;
        }
        return $files;
    }

    private function metadata(string $path): array
    {
        if (!is_file($path.'/metadata.json')) {
            return [];
        }
        return json_decode((string) file_get_contents($path.'/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function initialVersion(array $files): string
    {
        return 'initial-'.hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    private function put(string $path, string $text): void
    {
        $file = fopen($path, 'xb');
        if ($file === false) {
            throw new \RuntimeException('Cannot write a configuration file.');
        }
        try {
            if (fwrite($file, $text) !== strlen($text) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Configuration write did not complete.');
            }
            chmod($path, 0600);
        } finally {
            fclose($file);
        }
    }
}
