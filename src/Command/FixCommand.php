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
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create backup files before making changes')
            ->addOption('autoload-file', 'a', InputOption::VALUE_REQUIRED, 'Path to autoload file')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit for PHPStan execution')
            ->addOption('smart', null, InputOption::VALUE_NONE, 'Enable smart mode with multi-pass analysis and type caching')
            ->addOption('no-emojis', null, InputOption::VALUE_NONE, 'Disable emojis in output for better terminal compatibility')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up old backup files (.bak and .phpstan-fixer-backup)')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command analyzes your PHP code with PHPStan and automatically fixes found errors.

<info>php %command.full_name% src/</info>

You can specify the PHPStan level:
<info>php %command.full_name% src/ --level=5</info>

To see what would be fixed without making changes:
<info>php %command.full_name% src/ --dry-run</info>

To use a custom PHPStan configuration:
<info>php %command.full_name% src/ --config=phpstan.neon</info>

To increase memory limit for PHPStan:
<info>php %command.full_name% src/ --memory-limit=256M</info>

To create backup files before making changes:
<info>php %command.full_name% src/ --backup</info>

To enable smart mode with multi-pass analysis and type caching:
<info>php %command.full_name% src/ --smart</info>

To clean up old backup files:
<info>php %command.full_name% src/ --cleanup</info>
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
        /** @var bool $backup */
        $backup = (bool) $input->getOption('backup');
        /** @var bool $smartMode */
        $smartMode = (bool) $input->getOption('smart');
        /** @var bool $noEmojis */
        $noEmojis = (bool) $input->getOption('no-emojis');
        /** @var bool $cleanup */
        $cleanup = (bool) $input->getOption('cleanup');
        /** @var string|null $autoloadFile */
        $autoloadFile = $input->getOption('autoload-file');

        if ($level < 0 || $level > 9) {
            $io->error('Level must be between 0 and 9');
            return Command::FAILURE;
        }

        $io->title('PHPStan Auto-Fixer');
        $messages = [
            sprintf('Analyzing paths: %s', implode(', ', $paths)),
            sprintf('PHPStan level: %d', $level),
            $dryRun ? 'Mode: Dry run (no changes will be made)' : 'Mode: Fix errors',
        ];
        if ($smartMode) {
            $messages[] = 'Smart mode: Enabled (multi-pass analysis with type caching)';
        }
        $io->text($messages);

        if ($configFile) {
            $io->text(sprintf('Config file: %s', (string) $configFile));
        }

        $io->newLine();

        try {
            // Handle cleanup-only mode
            if ($cleanup) {
                $this->performCleanup($io, $paths, $noEmojis);
                return Command::SUCCESS;
            }
            
            $fixer = new PHPStanFixer();
            
            $options = [];
            if ($configFile) {
                $options['configuration'] = $configFile;
            }
            if ($autoloadFile) {
                $options['autoload-file'] = $autoloadFile;
            }
            
            $memoryLimit = $input->getOption('memory-limit');
            if ($memoryLimit) {
                $options['memory-limit'] = $memoryLimit;
            }

            if ($dryRun) {
                $io->section('Running analysis...');
                $result = $fixer->fix($paths, $level, $options, $backup, $smartMode);
                $this->displayDryRunResults($io, $result, $noEmojis);
            } else {
                $io->section('Fixing errors...');
                
                $progressBar = $io->createProgressBar();
                $progressBar->start();
                
                $result = $fixer->fix($paths, $level, $options, $backup, $smartMode);
                
                $progressBar->finish();
                $io->newLine(2);
                
                $this->displayResults($io, $result, $backup, $noEmojis);
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

    private function displayResults(SymfonyStyle $io, \PHPStanFixer\FixResult $result, bool $backup, bool $noEmojis = false): void
    {
        if ($result->getMessage()) {
            $io->success($result->getMessage());
            return;
        }

        $fixedCount = $result->getFixedCount();
        $unfixableCount = $result->getUnfixableCount();

        // Summary header with emoji and colors
        $io->newLine();
        $this->displaySummaryBox($io, $fixedCount, $unfixableCount, false, $noEmojis);

        if ($fixedCount > 0) {
            $io->newLine();
            $io->writeln('<fg=white;bg=green;options=bold> ✓ FIXED ERRORS </fg=white;bg=green;options=bold>');
            $io->newLine();

            $groupedErrors = $this->groupErrorsByFile($result->getFixedErrors());
            foreach ($groupedErrors as $file => $errors) {
                $io->writeln(sprintf('<fg=cyan;options=bold>📁 %s</fg=cyan;options=bold>', $this->shortenPath($file)));
                foreach ($errors as $error) {
                    $io->writeln(sprintf(
                        '   <fg=green>✓</fg=green> <fg=yellow>Line %d:</fg=yellow> <fg=white>%s</fg=white>',
                        $error->getLine(),
                        $error->getMessage()
                    ));
                }
                $io->newLine();
            }

            if ($backup) {
                $io->writeln('<fg=white;bg=blue;options=bold> 💾 BACKUP FILES </fg=white;bg=blue;options=bold>');
                $io->newLine();
                foreach ($result->getFixedFiles() as $file => $backupFile) {
                    if ($backupFile) {
                        $io->writeln(sprintf(
                            '   <fg=blue>💾</fg=blue> <fg=cyan>%s</fg=cyan> → <fg=yellow>%s</fg=yellow>',
                            $this->shortenPath($file),
                            basename($backupFile)
                        ));
                    }
                }
                $io->newLine();
            }
        }

        if ($unfixableCount > 0) {
            $io->writeln('<fg=white;bg=red;options=bold> ✗ UNFIXABLE ERRORS </fg=white;bg=red;options=bold>');
            $io->newLine();

            $groupedErrors = $this->groupErrorsByFile($result->getUnfixableErrors());
            foreach ($groupedErrors as $file => $errors) {
                $io->writeln(sprintf('<fg=cyan;options=bold>📁 %s</fg=cyan;options=bold>', $this->shortenPath($file)));
                foreach ($errors as $error) {
                    $io->writeln(sprintf(
                        '   <fg=red>✗</fg=red> <fg=yellow>Line %d:</fg=yellow> <fg=white>%s</fg=white>',
                        $error->getLine(),
                        $error->getMessage()
                    ));
                }
                $io->newLine();
            }
        }

        if ($result->getErrors()) {
            $io->writeln('<fg=white;bg=red;options=bold> ⚠ PROCESSING ERRORS </fg=white;bg=red;options=bold>');
            $io->newLine();
            foreach ($result->getErrors() as $error) {
                $io->writeln(sprintf('   <fg=red>⚠</fg=red> <fg=white>%s</fg=white>', $error));
            }
            $io->newLine();
        }
    }

    private function displayDryRunResults(SymfonyStyle $io, \PHPStanFixer\FixResult $result, bool $noEmojis = false): void
    {
        if ($result->getMessage()) {
            $io->success($result->getMessage());
            return;
        }

        $fixableCount = count($result->getFixedErrors());
        $unfixableCount = count($result->getUnfixableErrors());

        // Dry-run summary header
        $io->newLine();
        $io->writeln('<fg=white;bg=blue;options=bold> 🔍 DRY RUN ANALYSIS RESULTS </fg=white;bg=blue;options=bold>');
        $io->newLine();
        
        $this->displaySummaryBox($io, $fixableCount, $unfixableCount, true, $noEmojis);

        if ($fixableCount > 0) {
            $io->newLine();
            $io->writeln('<fg=white;bg=green;options=bold> ✓ CAN BE FIXED </fg=white;bg=green;options=bold>');
            $io->newLine();

            $groupedErrors = $this->groupErrorsByFile($result->getFixedErrors());
            foreach ($groupedErrors as $file => $errors) {
                $io->writeln(sprintf('<fg=cyan;options=bold>📁 %s</fg=cyan;options=bold>', $this->shortenPath($file)));
                foreach ($errors as $error) {
                    $io->writeln(sprintf(
                        '   <fg=green>✓</fg=green> <fg=yellow>Line %d:</fg=yellow> <fg=white>%s</fg=white>',
                        $error->getLine(),
                        $error->getMessage()
                    ));
                }
                $io->newLine();
            }
        }

        if ($unfixableCount > 0) {
            $io->writeln('<fg=white;bg=red;options=bold> ✗ CANNOT BE FIXED </fg=white;bg=red;options=bold>');
            $io->newLine();

            $groupedErrors = $this->groupErrorsByFile($result->getUnfixableErrors());
            foreach ($groupedErrors as $file => $errors) {
                $io->writeln(sprintf('<fg=cyan;options=bold>📁 %s</fg=cyan;options=bold>', $this->shortenPath($file)));
                foreach ($errors as $error) {
                    $io->writeln(sprintf(
                        '   <fg=red>✗</fg=red> <fg=yellow>Line %d:</fg=yellow> <fg=white>%s</fg=white>',
                        $error->getLine(),
                        $error->getMessage()
                    ));
                }
                $io->newLine();
            }
        }

        if ($fixableCount > 0) {
            $io->writeln('<fg=white;bg=magenta;options=bold> 🚀 NEXT STEPS </fg=white;bg=magenta;options=bold>');
            $io->newLine();
            $io->writeln(sprintf('   <fg=magenta>→</fg=magenta> <fg=white>Run without</fg=white> <fg=yellow>--dry-run</fg=yellow> <fg=white>to apply %d fixes</fg=white>', $fixableCount));
            $io->writeln('   <fg=magenta>→</fg=magenta> <fg=white>Add</fg=white> <fg=yellow>--backup</fg=yellow> <fg=white>to create backup files</fg=white>');
            $io->newLine();
        }
    }

    /**
     * Display a summary box with statistics
     */
    private function displaySummaryBox(SymfonyStyle $io, int $fixedCount, int $unfixableCount, bool $isDryRun = false, bool $noEmojis = false): void
    {
        $total = $fixedCount + $unfixableCount;
        $verb = $isDryRun ? 'analyzed' : 'processed';
        
        $io->writeln('┌─────────────────────────────────────────────────────────────┐');
        $io->writeln(sprintf('│ <fg=white;options=bold>Summary: %d errors %s</fg=white;options=bold>%s│', $total, $verb, str_repeat(' ', max(0, 36 - strlen((string)$total) - strlen($verb)))));
        $io->writeln('├─────────────────────────────────────────────────────────────┤');
        
        if ($fixedCount > 0) {
            $canBe = $isDryRun ? 'can be fixed' : 'fixed';
            $io->writeln(sprintf('│ <fg=green>✓ %d %s</fg=green>%s│', $fixedCount, $canBe, str_repeat(' ', max(0, 50 - strlen((string)$fixedCount) - strlen($canBe)))));
        }
        
        if ($unfixableCount > 0) {
            $cannot = $isDryRun ? 'cannot be fixed' : 'unfixable';
            $io->writeln(sprintf('│ <fg=red>✗ %d %s</fg=red>%s│', $unfixableCount, $cannot, str_repeat(' ', max(0, 46 - strlen((string)$unfixableCount) - strlen($cannot)))));
        }
        
        if ($total === 0) {
            $emoji = $noEmojis ? '' : '🎉 ';
            $io->writeln(sprintf('│ <fg=green>%sNo errors found!</fg=green>%s│', $emoji, str_repeat(' ', $noEmojis ? 43 : 40)));
        }
        
        $io->writeln('└─────────────────────────────────────────────────────────────┘');
    }

    /**
     * Group errors by file for better organization
     * 
     * @param array<\PHPStanFixer\ValueObjects\Error> $errors
     * @return array<string, array<\PHPStanFixer\ValueObjects\Error>>
     */
    private function groupErrorsByFile(array $errors): array
    {
        $grouped = [];
        foreach ($errors as $error) {
            $file = $error->getFile();
            if (!isset($grouped[$file])) {
                $grouped[$file] = [];
            }
            $grouped[$file][] = $error;
        }
        
        // Sort files alphabetically
        ksort($grouped);
        
        // Sort errors within each file by line number
        foreach ($grouped as &$fileErrors) {
            usort($fileErrors, function($a, $b) {
                return $a->getLine() <=> $b->getLine();
            });
        }
        
        return $grouped;
    }

    /**
     * Shorten file paths for better readability
     */
    private function shortenPath(string $path): string
    {
        // Get current working directory
        $cwd = getcwd();
        if ($cwd && strpos($path, $cwd) === 0) {
            return '.' . substr($path, strlen($cwd));
        }
        
        // If path is too long, show just the filename and parent directory
        if (strlen($path) > 60) {
            $parts = explode('/', $path);
            if (count($parts) > 2) {
                return '.../' . $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1];
            }
        }
        
        return $path;
    }

    /**
     * Get emoji or fallback text based on user preference
     */
    private function emoji(string $emoji, string $fallback = '', bool $noEmojis = false): string
    {
        return $noEmojis ? $fallback : $emoji;
    }

    /**
     * Perform cleanup of old backup files
     */
    private function performCleanup(SymfonyStyle $io, array $paths, bool $noEmojis = false): void
    {
        $io->title($this->emoji('🧹', '[CLEANUP]', $noEmojis) . ' Backup Files Cleanup');
        
        $totalRemoved = 0;
        $projectRoot = \PHPStanFixer\Utilities\ProjectRootDetector::detectFromPaths($paths);
        
        // 1. Clean up atomic transaction backups (.phpstan-fixer-backup directory)
        $atomicApplicator = new \PHPStanFixer\Utilities\AtomicFixApplicator($projectRoot);
        $atomicRemoved = $atomicApplicator->cleanupOldBackups(0); // Remove all
        $totalRemoved += $atomicRemoved;
        
        if ($atomicRemoved > 0) {
            $io->writeln(sprintf(
                '   %s Removed %d atomic backup file(s) from .phpstan-fixer-backup/',
                $this->emoji('🗑️', '[REMOVED]', $noEmojis),
                $atomicRemoved
            ));
        }
        
        // 2. Clean up legacy .bak files
        $bakRemoved = $this->cleanupLegacyBackups($paths);
        $totalRemoved += $bakRemoved;
        
        if ($bakRemoved > 0) {
            $io->writeln(sprintf(
                '   %s Removed %d legacy .phpstan-fixer.bak file(s)',
                $this->emoji('🗑️', '[REMOVED]', $noEmojis),
                $bakRemoved
            ));
        }
        
        // 3. Summary
        if ($totalRemoved > 0) {
            $io->newLine();
            $io->success(sprintf(
                '%s Successfully cleaned up %d backup file(s)!',
                $this->emoji('✨', '', $noEmojis),
                $totalRemoved
            ));
        } else {
            $io->info($this->emoji('✨', '', $noEmojis) . ' No old backup files found to clean up.');
        }
    }

    /**
     * Clean up legacy .phpstan-fixer.bak files
     */
    private function cleanupLegacyBackups(array $paths): int
    {
        $removed = 0;
        
        foreach ($paths as $path) {
            if (is_file($path)) {
                // Single file - check for its backup
                $backupFile = $path . '.phpstan-fixer.bak';
                if (file_exists($backupFile)) {
                    try {
                        unlink($backupFile);
                        $removed++;
                    } catch (\Exception $e) {
                        error_log("Failed to remove backup {$backupFile}: " . $e->getMessage());
                    }
                }
            } elseif (is_dir($path)) {
                // Directory - find all .bak files recursively
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && str_ends_with($file->getPathname(), '.phpstan-fixer.bak')) {
                        try {
                            unlink($file->getPathname());
                            $removed++;
                        } catch (\Exception $e) {
                            error_log("Failed to remove backup {$file->getPathname()}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        return $removed;
    }
}