<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Configuration\Infrastructure\Migration\LegacyImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:configuration:import-legacy', description: 'Import Polish configuration alongside its source without changing processing state.')]
final class ImportLegacyCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED)->addArgument('destination', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = (new LegacyImporter())->import($input->getArgument('source'), $input->getArgument('destination'));
        } catch (\Throwable $e) {
            $output->writeln('Import failed: '.$e->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        }
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
