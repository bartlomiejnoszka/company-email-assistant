<?php

declare(strict_types=1);

namespace App\Interface\Mcp;

use App\Configuration\Domain\ConfigurationFiles;
use App\Configuration\Application\ConfigurationManager;
use Mcp\Capability\Attribute\{McpTool, McpResource, Schema};
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ConfigurationTools
{
    private const OUTPUT_SCHEMA = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => true];
    private const READ_AUTH = ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['configuration:read']]]];
    private const WRITE_AUTH = ['securitySchemes' => [['type' => 'oauth2', 'scopes' => ['configuration:read', 'configuration:write']]]];
    private const FILE_SCHEMA = ['type' => 'object', 'properties' => ['company.yaml' => ['type' => 'string'], 'knowledge.yaml' => ['type' => 'string'], 'pricing.yaml' => ['type' => 'string']], 'required' => ['company.yaml', 'knowledge.yaml', 'pricing.yaml'], 'additionalProperties' => false];

    public function __construct(private readonly ConfigurationManager $store, private readonly ConfigurationGuidance $guidance, private readonly AuthorizationCheckerInterface $authorization)
    {
    }

    #[McpTool(name: 'get_configuration', description: 'Read the current three YAML files, version and configuration guidance. Call before proposing edits.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false), meta: self::READ_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function getConfiguration(): array
    {
        $this->requireRead();
        return ['ok' => true, ...$this->store->read(), 'guidance' => $this->guidance->get()];
    }

    #[McpTool(name: 'get_configuration_guidance', description: 'Learn valid preferences, defaults, case overrides, dependencies and approved configuration examples.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false), meta: self::READ_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function getGuidance(): array
    {
        $this->requireRead();
        return ['ok' => true, 'guidance' => $this->guidance->get()];
    }

    #[McpResource(uri: 'assistant://configuration/guidance', name: 'configuration-guidance', description: 'Owner guide and current preference rules', mimeType: 'application/json')]
    public function guidanceResource(): string
    {
        $this->requireRead();
        return json_encode($this->guidance->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    #[McpTool(name: 'validate_configuration', description: 'Validate a complete proposed YAML package without activation. Returns a diff for review; obtain confirmation before saving.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false), meta: self::READ_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function validateConfiguration(#[Schema(definition: self::FILE_SCHEMA)] array $files, string $expected_version): array
    {
        $this->requireRead();
        return $this->attempt(function () use ($files, $expected_version) {
            $current = $this->store->read();
            $this->checkVersion($current['version'], $expected_version);
            $this->store->validate($files);
            $diff = [];
            foreach (ConfigurationFiles::NAMES as $name) {
                if ($files[$name] !== $current['files'][$name]) {
                    $diff[$name] = ['before' => $current['files'][$name], 'after' => $files[$name]];
                }
            }
            return ['ok' => true, 'valid' => true, 'version' => $current['version'], 'diff' => $diff ?: new \stdClass(), 'activation' => 'Not activated. Show this diff and ask for explicit confirmation.'];
        });
    }

    #[McpTool(name: 'save_configuration', description: 'Activate a validated complete package only after showing the diff and obtaining explicit user confirmation. Saves all files atomically. Stale versions are rejected.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false), meta: self::WRITE_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function saveConfiguration(#[Schema(definition: self::FILE_SCHEMA)] array $files, string $expected_version, bool $confirmed): array
    {
        $this->requireWrite();
        if (!$confirmed) {
            return ['ok' => false, 'error' => 'confirmation_required', 'message' => 'Show the diff and obtain explicit user confirmation first.'];
        }
        return $this->attempt(fn () => ['ok' => true, 'version' => $this->store->save($files, $expected_version), 'message' => 'Activated. Existing drafts are unchanged.']);
    }

    #[McpTool(name: 'list_configuration_versions', description: 'Read configuration version history and identify the previous version available to restore.', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false), meta: self::READ_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function listVersions(): array
    {
        $this->requireRead();
        return ['ok' => true, 'versions' => $this->store->history()];
    }

    #[McpTool(name: 'restore_previous_configuration', description: 'Restore all three files from the immediately previous version, preserving history. Obtain explicit user confirmation first.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false), meta: self::WRITE_AUTH, outputSchema: self::OUTPUT_SCHEMA)]
    public function restorePrevious(string $expected_version, bool $confirmed): array
    {
        $this->requireWrite();
        if (!$confirmed) {
            return ['ok' => false, 'error' => 'confirmation_required'];
        }
        return $this->attempt(fn () => ['ok' => true, 'version' => $this->store->restore($expected_version)]);
    }

    private function requireRead(): void
    {
        if (!$this->authorization->isGranted('ROLE_CONFIG_READ')) {
            throw new \Mcp\Exception\ToolCallException('configuration:read required');
        }
    }
    private function requireWrite(): void
    {
        $this->requireRead();
        if (!$this->authorization->isGranted('ROLE_CONFIG_WRITE')) {
            throw new \Mcp\Exception\ToolCallException('configuration:write required');
        }
    }
    private function checkVersion(string $current, string $expected): void
    {
        if (!hash_equals($current, $expected)) {
            throw new \DomainException('Configuration changed. Read again, review the new diff and confirm again.');
        }
    }
    private function attempt(callable $action): array
    {
        try {
            return $action();
        } catch (\DomainException $e) {
            return ['ok' => false, 'error' => 'version_conflict_or_no_previous', 'message' => $e->getMessage()];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => 'validation_failed', 'message' => $e->getMessage()];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'storage_unavailable', 'message' => 'Configuration operation failed. No activation was confirmed; read the current version before retrying.'];
        }
    }
}
