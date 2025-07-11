<?php

declare(strict_types=1);

namespace PHPStanFixer\Command;

use PHPStanFixer\PHPStanFixer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FixCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('fix')
            ->setDescription('Fix PHPStan errors automatically')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Paths to analyze and fix')
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, 'PHPStan level (0-9)', '0')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to PHPStan configuration file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be fixed without making changes')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create backup files')
            ->addOption('autoload-file', 'a', InputOption::VALUE_REQUIRED, 'Path to autoload file')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command analyzes your PHP code with PHPStan and automatically fixes found errors.

<info>php %command.full_name% src/</info>

You can specify the PHPStan level:
<info>php %command.full_name% src/ --level=5</info>

To see what would be fixed without making changes:
<info>php %command.full_name% src/ --dry-run</info>

To use a custom PHPStan configuration:
<info>php %command.full_name% src/ --config=phpstan.neon</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        /** @var array<string> $paths */
        $paths = $input->getArgument('paths');
        $levelInput = $input->getOption('level');
        if (!is_numeric($levelInput)) {
            $io->error('Level must be a number between 0 and 9');
            return Command::FAILURE;
        }
        $level = (int) $levelInput;
        /** @var string|null $configFile */
        $configFile = $input->getOption('config');
        /** @var bool $dryRun */
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var bool $noBackup */
        $noBackup = (bool) $input->getOption('no-backup');
        /** @var string|null $autoloadFile */
        $autoloadFile = $input->getOption('autoload-file');

        if ($level < 0 || $level > 9) {
            $io->error('Level must be between 0 and 9');
            return Command::FAILURE;
        }

        $io->title('PHPStan Auto-Fixer');
        $io->text([
            sprintf('Analyzing paths: %s', implode(', ', $paths)),
            sprintf('PHPStan level: %d', $level),
            $dryRun ? 'Mode: Dry run (no changes will be made)' : 'Mode: Fix errors',
        ]);

        if ($configFile) {
            $io->text(sprintf('Config file: %s', (string) $configFile));
        }

        $io->newLine();

        try {
            $fixer = new PHPStanFixer();
            
            $options = [];
            if ($configFile) {
                $options['configuration'] = $configFile;
            }
            if ($autoloadFile) {
                $options['autoload-file'] = $autoloadFile;
            }

            if ($dryRun) {
                $io->section('Running analysis...');
                $result = $fixer->fix($paths, $level, $options);
                $this->displayDryRunResults($io, $result);
            } else {
                $io->section('Fixing errors...');
                
                $progressBar = $io->createProgressBar();
                $progressBar->start();
                
                $result = $fixer->fix($paths, $level, $options);
                
                $progressBar->finish();
                $io->newLine(2);
                
                $this->displayResults($io, $result, $noBackup);
            }

            if ($result->hasErrors()) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayResults(SymfonyStyle $io, \PHPStanFixer\FixResult $result, bool $noBackup): void
    {
        if ($result->getMessage()) {
            $io->success($result->getMessage());
            return;
        }

        $fixedCount = $result->getFixedCount();
        $unfixableCount = $result->getUnfixableCount();

        if ($fixedCount > 0) {
            $io->success(sprintf('Fixed %d error(s)', $fixedCount));

            $io->section('Fixed errors:');
            foreach ($result->getFixedErrors() as $error) {
                $io->text(sprintf(
                    '  ✓ %s:%d - %s',
                    $error->getFile(),
                    $error->getLine(),
                    $error->getMessage()
                ));
            }

            if (!$noBackup) {
                $io->newLine();
                $io->section('Backup files created:');
                foreach ($result->getFixedFiles() as $file => $backupFile) {
                    $io->text(sprintf('  %s → %s', $file, $backupFile));
                }
            }
        }

        if ($unfixableCount > 0) {
            $io->warning(sprintf('Could not fix %d error(s)', $unfixableCount));

            $io->section('Unfixable errors:');
            foreach ($result->getUnfixableErrors() as $error) {
                $io->text(sprintf(
                    '  ✗ %s:%d - %s',
                    $error->getFile(),
                    $error->getLine(),
                    $error->getMessage()
                ));
            }
        }

        if ($result->getErrors()) {
            $io->error('The following errors occurred:');
            foreach ($result->getErrors() as $error) {
                $io->text('  - ' . $error);
            }
        }
    }

    private function displayDryRunResults(SymfonyStyle $io, \PHPStanFixer\FixResult $result): void
    {
        if ($result->getMessage()) {
            $io->success($result->getMessage());
            return;
        }

        $fixableCount = count($result->getFixedErrors());
        $unfixableCount = count($result->getUnfixableErrors());

        $io->section('Analysis Results');

        if ($fixableCount > 0) {
            $io->text(sprintf('<info>%d error(s) can be fixed:</info>', $fixableCount));
            foreach ($result->getFixedErrors() as $error) {
                $io->text(sprintf(
                    '  ✓ %s:%d - %s',
                    $error->getFile(),
                    $error->getLine(),
                    $error->getMessage()
                ));
            }
        }

        if ($unfixableCount > 0) {
            $io->newLine();
            $io->text(sprintf('<comment>%d error(s) cannot be automatically fixed:</comment>', $unfixableCount));
            foreach ($result->getUnfixableErrors() as $error) {
                $io->text(sprintf(
                    '  ✗ %s:%d - %s',
                    $error->getFile(),
                    $error->getLine(),
                    $error->getMessage()
                ));
            }
        }

        $io->newLine();
        $io->note('Run without --dry-run to apply these fixes.');
    }
}