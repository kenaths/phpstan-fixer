<?php

declare(strict_types=1);

namespace PHPStanFixer\Runner;

use Symfony\Component\Process\Process;
use InvalidArgumentException;

class PHPStanRunner
{
    private string $phpstanPath;
    private int $timeoutSeconds;
    private const MAX_PATHS = 1000;
    private const ALLOWED_OPTION_KEYS = [
        'configuration', 'level', 'no-progress', 'error-format', 'memory-limit',
        'autoload-file', 'debug', 'verbose', 'help', 'version', 'ansi', 'no-ansi',
        'quiet', 'no-interaction'
    ];

    public function __construct(?string $phpstanPath = null, int $timeoutSeconds = 300)
    {
        $this->phpstanPath = $phpstanPath ?? $this->findPHPStan();
        $this->timeoutSeconds = $timeoutSeconds;
        
        // Validate PHPStan path for security
        $this->validatePHPStanPath($this->phpstanPath);
    }

    /**
     * @param array<string> $paths
     * @param array<string, mixed> $options
     */
    public function analyze(array $paths, int $level, array $options = []): string
    {
        // Security validation
        $this->validateAnalysisInputs($paths, $level, $options);
        
        // Build secure command
        $command = $this->buildSecureCommand($paths, $level, $options);

        // Execute with timeout protection
        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);
        $process->setIdleTimeout($this->timeoutSeconds);
        
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            throw new \RuntimeException(
                "PHPStan analysis timed out after {$this->timeoutSeconds} seconds. " .
                "Consider reducing the scope or increasing the timeout.",
                0,
                $e
            );
        }

        // Parse output securely
        return $this->parseProcessOutput($process);
    }

    /**
     * Validate PHPStan executable path for security
     */
    private function validatePHPStanPath(string $path): void
    {
        // Prevent path traversal attacks
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid PHPStan path: contains dangerous characters');
        }
        
        // Ensure the path is not empty and is a reasonable length
        if (empty($path) || strlen($path) > 500) {
            throw new InvalidArgumentException('Invalid PHPStan path: empty or too long');
        }
        
        // Ensure it's an executable file
        if (!is_executable($path)) {
            throw new InvalidArgumentException("PHPStan executable not found or not executable: {$path}");
        }
    }

    /**
     * Validate all inputs to the analyze method
     * 
     * @param array<string> $paths
     * @param array<string, mixed> $options
     */
    private function validateAnalysisInputs(array $paths, int $level, array $options): void
    {
        // Validate level
        if ($level < 0 || $level > 10) {
            throw new InvalidArgumentException('PHPStan level must be between 0 and 10');
        }
        
        // Validate paths
        if (empty($paths)) {
            throw new InvalidArgumentException('At least one path must be provided for analysis');
        }
        
        if (count($paths) > self::MAX_PATHS) {
            throw new InvalidArgumentException('Too many paths provided (max ' . self::MAX_PATHS . ')');
        }
        
        foreach ($paths as $path) {
            $this->validatePath($path);
        }
        
        // Validate options
        $this->validateOptions($options);
    }

    /**
     * Validate a single path for security
     */
    private function validatePath(string $path): void
    {
        if (!is_string($path) || empty($path)) {
            throw new InvalidArgumentException('Path must be a non-empty string');
        }
        
        if (strlen($path) > 4096) {
            throw new InvalidArgumentException('Path too long (max 4096 characters)');
        }
        
        // Prevent null byte injection
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains null byte');
        }
        
        // Basic path traversal protection (more thorough checking in file operations)
        $normalizedPath = realpath($path);
        if ($normalizedPath === false && !file_exists($path)) {
            // Path doesn't exist - this is OK as PHPStan should handle it
            // But we still need to check for obvious attacks
            if (str_contains($path, '..')) {
                throw new InvalidArgumentException('Path contains directory traversal attempts');
            }
        }
    }

    /**
     * Validate options array for security
     * 
     * @param array<string, mixed> $options
     */
    private function validateOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            // Validate option key
            if (!is_string($key) || empty($key)) {
                throw new InvalidArgumentException('Option key must be a non-empty string');
            }
            
            // Check against allowed options list
            if (!in_array($key, self::ALLOWED_OPTION_KEYS, true)) {
                throw new InvalidArgumentException("Disallowed option key: {$key}");
            }
            
            // Validate option value
            if (is_string($value)) {
                if (strlen($value) > 1000) {
                    throw new InvalidArgumentException("Option value too long for key: {$key}");
                }
                
                if (str_contains($value, "\0")) {
                    throw new InvalidArgumentException("Option value contains null byte for key: {$key}");
                }
            } elseif (!is_bool($value) && !is_int($value)) {
                throw new InvalidArgumentException("Option value must be string, bool, or int for key: {$key}");
            }
        }
    }

    /**
     * Build a secure command array with proper escaping
     * 
     * @param array<string> $paths
     * @param array<string, mixed> $options
     * @return array<string>
     */
    private function buildSecureCommand(array $paths, int $level, array $options): array
    {
        $command = [
            $this->phpstanPath,
            'analyse',
            '--level=' . $level,
            '--no-progress',
            '--error-format=json',
        ];

        // Add validated options
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $command[] = '--' . $key;
                }
            } else {
                // Escape the value to prevent injection
                $command[] = '--' . $key . '=' . (string) $value;
            }
        }

        // Add validated paths (Process class handles escaping automatically)
        foreach ($paths as $path) {
            $command[] = $path;
        }

        return $command;
    }

    /**
     * Safely parse process output
     */
    private function parseProcessOutput(Process $process): string
    {
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        
        // PHPStan outputs JSON to stderr when there are errors
        if (!empty($errorOutput)) {
            $lines = explode("\n", $errorOutput);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!empty($trimmed) && $trimmed[0] === '{') {
                    // Validate JSON before returning
                    if (json_decode($trimmed) !== null) {
                        return $trimmed;
                    }
                }
            }
        }
        
        return $output;
    }

    private function findPHPStan(): string
    {
        $candidates = [
            'vendor/bin/phpstan',
            '../vendor/bin/phpstan',
            '../../vendor/bin/phpstan',
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