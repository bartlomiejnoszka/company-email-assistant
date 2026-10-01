<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Identity\Infrastructure\OAuth\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:mcp:revoke', description: 'Revoke all owner MCP access, refresh tokens and pending authorization codes.')]
final class RevokeMcpCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->settings->read();
        if (is_file($this->settings->directory.'/oauth.sqlite')) {
            $db = new \PDO('sqlite:'.$this->settings->directory.'/oauth.sqlite');
            $db->exec('PRAGMA busy_timeout=5000; UPDATE tokens SET revoked=1');
        }
        $output->writeln('All MCP grants revoked. Reconnect ChatGPT to authorize again.');
        return Command::SUCCESS;
    }
}
