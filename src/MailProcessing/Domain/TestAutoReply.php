<?php

declare(strict_types=1);

namespace App\MailProcessing\Domain;

use App\Configuration\Domain\CompanyConfig;

/** Opt-in test delivery, restricted to a single exact To recipient. */
final class TestAutoReply
{
    public function __construct(private readonly bool $enabled = false, private readonly array $allowlist = [])
    {
        foreach ($allowlist as $address) {
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Auto-send allowlist must contain email addresses.');
            }
        }
        if ($enabled && !$allowlist) {
            throw new \InvalidArgumentException('Auto-send requires an explicit nonempty recipient allowlist.');
        }
    }
    public function enabled(): bool
    {
        return $this->enabled;
    }
    public function matches(SourceMessage $source): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $raw = trim($source->header('To') ?? '');
        foreach ($this->allowlist as $address) {
            $pattern = preg_quote($address, '/');
            if (preg_match('/^(?:'.$pattern.'|(?:"[^"<>\r\n]*"|[^<>;,\r\n]*)<'.$pattern.'>)$/iD', $raw)) {
                return true;
            }
        }
        return false;
    }
    public function assertRecipient(string $recipient, CompanyConfig $config): void
    {
        $normalize = static fn (string $address): string => preg_replace('/\+[^@]*(?=@)/', '', strtolower($address));
        $blocked = array_map($normalize, [...$this->allowlist, $config->company['sender_address']]);
        if (in_array($normalize($recipient), $blocked, true)) {
            throw new ManualHandling('auto_reply_loop');
        }
    }
}
