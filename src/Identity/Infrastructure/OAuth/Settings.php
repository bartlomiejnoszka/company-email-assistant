<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth;

final class Settings
{
    public function __construct(public readonly string $directory, public readonly string $baseUrl)
    {
    }
    public function resource(): string
    {
        return rtrim($this->baseUrl, '/').'/mcp';
    }
    public function read(): array
    {
        $path = $this->directory.'/settings.json';
        if (!is_file($path)) {
            throw new \RuntimeException('MCP owner account is not configured.');
        }
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
