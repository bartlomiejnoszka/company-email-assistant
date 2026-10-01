<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\MailProcessing\Application\ProcessMailbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:email:generate-drafts', description: 'Generate approved-content drafts for the configured company.')]
final class GenerateDraftsCommand extends Command
{
    public function __construct(private readonly ProcessMailbox $processor)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without mailbox or state mutations')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum new/retry messages', '50');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->processor->run((bool) $input->getOption('dry-run'), $input->getOption('limit'), static fn (string $message) => $output->writeln($message, OutputInterface::OUTPUT_RAW));
    }
}
