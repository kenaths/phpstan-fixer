<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes redundant type checks and impossible conditions
 */
class RedundantTypeCheckFixer implements FixerInterface
{
    public function getSupportedTypes(): array
    {
        return [
            'redundant_type_check',
            'always_false_condition',
            'always_true_condition',
        ];
    }

    public function canFix(Error $error): bool
    {
        return $this->isRedundantTypeCheck($error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $message = $error->getMessage();
        
        if (str_contains($message, 'will always evaluate to false')) {
            return $this->fixAlwaysFalseCondition($content, $error);
        }
        
        if (str_contains($message, 'will always evaluate to true')) {
            return $this->fixAlwaysTrueCondition($content, $error);
        }
        
        if (str_contains($message, 'If condition is always false')) {
            return $this->fixAlwaysFalseIfCondition($content, $error);
        }
        
        if (str_contains($message, 'Result of || is always true')) {
            return $this->fixAlwaysTrueOrCondition($content, $error);
        }
        
        return $content;
    }

    private function isRedundantTypeCheck(string $message): bool
    {
        $patterns = [
            'will always evaluate to false',
            'will always evaluate to true', 
            'If condition is always false',
            'Result of || is always true',
            'Instanceof between \*NEVER\* and',
        ];
        
        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    private function fixAlwaysFalseCondition(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1; // Convert to 0-based index
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // Handle is_array() with string will always evaluate to false
        if (str_contains($error->getMessage(), 'Call to function is_array()')) {
            // Replace is_array() check with false
            $line = preg_replace('/is_array\([^)]+\)/', 'false', $line);
            $lines[$lineNumber] = $line;
            return implode("\n", $lines);
        }
        
        return $content;
    }

    private function fixAlwaysTrueCondition(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // Handle is_string() with string will always evaluate to true
        if (str_contains($error->getMessage(), 'Call to function is_string()')) {
            // Replace is_string() check with true
            $line = preg_replace('/is_string\([^)]+\)/', 'true', $line);
            $lines[$lineNumber] = $line;
            return implode("\n", $lines);
        }
        
        return $content;
    }

    private function fixAlwaysFalseIfCondition(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        // For always false if conditions, comment out the entire if block
        $line = $lines[$lineNumber];
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $lines[$lineNumber] = $indent . '// ' . trim($line) . ' // Always false - commented out by PHPStan fixer';
        
        return implode("\n", $lines);
    }

    private function fixAlwaysTrueOrCondition(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // For always true OR conditions, we need to be careful
        // Often this means we can simplify the logic
        // For now, add a comment to indicate the issue
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $lines[$lineNumber] = $line . ' // TODO: Simplify - this OR condition is always true';
        
        return implode("\n", $lines);
    }
}