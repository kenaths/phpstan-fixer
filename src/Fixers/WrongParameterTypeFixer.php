<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes wrong parameter types in method calls
 */
class WrongParameterTypeFixer implements FixerInterface
{
    public function getSupportedTypes(): array
    {
        return [
            'wrong_parameter_type',
            'invalid_foreach_argument',
            'invalid_method_call',
        ];
    }

    public function canFix(Error $error): bool
    {
        $message = $error->getMessage();
        
        // Handle constructor parameter type mismatches
        if (str_contains($message, 'constructor expects') && str_contains($message, 'given')) {
            return true;
        }
        
        // Handle method call with wrong iterator type
        if (str_contains($message, 'supplied for foreach, only iterables are supported')) {
            return true;
        }
        
        // Handle method calls that cannot be made
        if (str_contains($message, 'Cannot call method') && str_contains($message, 'on string')) {
            return true;
        }
        
        return false;
    }

    public function fix(string $content, Error $error): string
    {
        $message = $error->getMessage();
        
        if (str_contains($message, 'constructor expects') && str_contains($message, 'given')) {
            return $this->fixConstructorParameterType($content, $error);
        }
        
        if (str_contains($message, 'supplied for foreach, only iterables are supported')) {
            return $this->fixForeachIterableType($content, $error);
        }
        
        if (str_contains($message, 'Cannot call method') && str_contains($message, 'on string')) {
            return $this->fixMethodCallOnString($content, $error);
        }
        
        return $content;
    }

    private function fixConstructorParameterType(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // For now, add a comment indicating the type mismatch needs manual review
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $comment = '// TODO: Fix parameter type mismatch - ' . $error->getMessage();
        
        // Add comment before the problematic line
        array_splice($lines, $lineNumber, 0, [$indent . $comment]);
        
        return implode("\n", $lines);
    }

    private function fixForeachIterableType(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // If it's a foreach on a string, we can't easily fix this automatically
        // Add a comment for manual review
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $comment = '// TODO: Variable used in foreach is not iterable - ' . $error->getMessage();
        
        array_splice($lines, $lineNumber, 0, [$indent . $comment]);
        
        return implode("\n", $lines);
    }

    private function fixMethodCallOnString(string $content, Error $error): string
    {
        $lines = explode("\n", $content);
        $lineNumber = $error->getLine() - 1;
        
        if (!isset($lines[$lineNumber])) {
            return $content;
        }
        
        $line = $lines[$lineNumber];
        
        // Add a comment indicating the method call issue
        $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
        $comment = '// TODO: Cannot call method on string - check variable type - ' . $error->getMessage();
        
        array_splice($lines, $lineNumber, 0, [$indent . $comment]);
        
        return implode("\n", $lines);
    }
}