<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Identity\Infrastructure\OAuth\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:owner:configure', aliases: ['app:mcp:configure'], description: 'Provision one owner OAuth account and signing keys in protected storage.')]
final class ConfigureMcpCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED, 'Optional exact ChatGPT callback for a predefined client (CIMD is enabled without this).');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->settings->directory;
        if (is_file($dir.'/settings.json')) {
            $output->writeln('Already configured; existing credentials were preserved.');
            return Command::SUCCESS;
        }
        $redirect = $input->getOption('redirect-uri');
        if ($redirect !== null && !preg_match('~^https://chatgpt\.com/[^\s#]*$~D', $redirect)) {
            $output->writeln('Use the exact HTTPS ChatGPT callback URI.');
            return Command::INVALID;
        }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Cannot create OAuth directory.');
        }
        chmod($dir, 0700);
        $lock = fopen($dir.'/.setup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock OAuth setup.');
        }
        try {
            if (is_file($dir.'/settings.json')) {
                return Command::SUCCESS;
            }
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || !openssl_pkey_export($key, $private)) {
                throw new \RuntimeException('Cannot create signing keys.');
            }
            $public = openssl_pkey_get_details($key)['key'];
            $password = bin2hex(random_bytes(16));
            $secret = bin2hex(random_bytes(32));
            $config = ['owner_password_hash' => password_hash($password, PASSWORD_DEFAULT), 'encryption_key' => bin2hex(random_bytes(32)), 'client_id' => 'company-assistant', 'redirect_uris' => $redirect ? [$redirect] : [], 'client_secret_hash' => password_hash($secret, PASSWORD_DEFAULT)];
            $this->put($dir.'/private.key', $private);
            $this->put($dir.'/public.key', $public);
            $this->put($dir.'/connection.txt', "MCP URL: ".$this->settings->resource()."\nAuthentication: OAuth\nOwner password: ".$password."\nClient ID (predefined-client fallback): company-assistant\nClient secret (predefined-client fallback): ".$secret."\nDefault: ChatGPT CIMD with PKCE, no client secret needed.\n");
            $this->put($dir.'/settings.json', json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $output->writeln('Owner account created. Credentials are in '.$dir.'/connection.txt (0600); no secrets printed.');
            return Command::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    private function put(string $path, string $content): void
    {
        $file = fopen($path, 'xb');
        if ($file === false) {
            throw new \RuntimeException('Refusing to overwrite existing OAuth material.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($file, $content) !== strlen($content) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('OAuth material write failed.');
            }
        } finally {
            fclose($file);
        }
    }
}
