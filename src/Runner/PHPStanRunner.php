<?php

declare(strict_types=1);

namespace PHPStanFixer\Runner;

use PHPStanFixer\Util\AutoloadUtil;
use Symfony\Component\Process\Process;

class PHPStanRunner
{
    private string $phpstanPath;

    public function __construct(?string $phpstanPath = null)
    {
        $this->phpstanPath = $phpstanPath ?? $this->findPHPStan();
    }

    /**
     * @param array<string> $paths
     * @param array<string, mixed> $options
     */
    public function analyze(array $paths, int $level, array $options = []): string
    {
        $command = [
            $this->phpstanPath,
            'analyse',
            '--level=' . $level,
            '--no-progress',
            '--error-format=json',
        ];

        // Add additional options
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $command[] = '--' . $key;
                }
            } else {
                $command[] = '--' . $key . '=' . $value;
            }
        }

        // Add paths
        $command = array_merge($command, $paths);

        $process = new Process($command);
        $process->run();

        // PHPStan outputs JSON to stderr when there are errors
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        
        // Try to parse JSON from error output first
        if (!empty($errorOutput)) {
            $lines = explode("\n", $errorOutput);
            foreach ($lines as $line) {
                if (trim($line) && $line[0] === '{') {
                    return trim($line);
                }
            }
        }
        
        return $output;
    }

    private function findPHPStan(): string
    {
        $autoloadUtil = new AutoloadUtil();

        $candidates = [
            'vendor/bin/phpstan',
            '../vendor/bin/phpstan',
            '../../vendor/bin/phpstan',
            $autoloadUtil->getBinFolder() . '/phpstan',
            'phpstan',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('PHPStan executable not found');
    }
}