<?php

declare(strict_types=1);

namespace PHPStanFixer\Parser;

use PHPStanFixer\ValueObjects\Error;

/**
 * Parses PHPStan output with support for modern PHPStan versions
 */
class ErrorParser
{
    /**
     * @return array<Error>
     */
    public function parse(string $phpstanOutput): array
    {
        $errors = [];
        
        try {
            /** @var array{files?: array<string, array{messages?: array<array{line?: int, message?: string, identifier?: string|null, severity?: int|null}>}>, errors?: array<string|array{file?: string, line?: int, message: string, identifier?: string|null}>} $data */
            $data = json_decode($phpstanOutput, true, 512, JSON_THROW_ON_ERROR);
            
            // Handle modern PHPStan JSON format
            if (isset($data['files'])) {
                foreach ($data['files'] as $file => $fileData) {
                    if (!isset($fileData['messages'])) {
                        continue;
                    }
                    
                    foreach ($fileData['messages'] as $message) {
                        $errors[] = new Error(
                            file: (string) $file,
                            line: (int) ($message['line'] ?? 0),
                            message: (string) ($message['message'] ?? ''),
                            identifier: isset($message['identifier']) ? (string) $message['identifier'] : null,
                            severity: isset($message['severity']) ? (int) $message['severity'] : null
                        );
                    }
                }
            }
            
            // Handle errors array (errors not tied to specific files)
            if (isset($data['errors'])) {
                foreach ($data['errors'] as $error) {
                    if (is_string($error)) {
                        // Simple error string
                        $errors[] = new Error(
                            file: 'unknown',
                            line: 0,
                            message: $error
                        );
                    } elseif (is_array($error)) {
                        // Structured error
                        $errors[] = new Error(
                            file: isset($error['file']) ? (string) $error['file'] : 'unknown',
                            line: isset($error['line']) ? (int) $error['line'] : 0,
                            message: (string) $error['message'],
                            identifier: isset($error['identifier']) ? (string) $error['identifier'] : null
                        );
                    }
                }
            }
        } catch (\JsonException $e) {
            // Try to parse text output as fallback
            $errors = $this->parseTextOutput($phpstanOutput);
        }
        
        return $errors;
    }

    /**
     * @return array<Error>
     */
    private function parseTextOutput(string $output): array
    {
        $errors = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and separators
            if (empty($line) || str_starts_with($line, '--') || str_starts_with($line, ' ')) {
                continue;
            }
            
            // Match pattern: file.php:123:Error message
            if (preg_match('/^(.+):(\d+):(.+)$/', $line, $matches)) {
                $errors[] = new Error(
                    file: $matches[1],
                    line: (int) $matches[2],
                    message: trim($matches[3])
                );
                continue;
            }
            
            // Match pattern from table format: :123 Error message
            if (preg_match('/^:(\d+)\s+(.+)$/', $line, $matches)) {
                // Try to get filename from previous context
                $errors[] = new Error(
                    file: $this->extractFileFromContext($lines) ?? 'unknown',
                    line: (int) $matches[1],
                    message: trim($matches[2])
                );
            }
        }
        
        return $errors;
    }

    /**
     * @param array<string> $lines
     */
    private function extractFileFromContext(array $lines): ?string
    {
        // Look for file path in previous lines
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^(.+\.php)/', $line, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
}