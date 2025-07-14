<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PhpParser\Node\Stmt\Class_;

class AsymmetricVisibilityFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['asymmetric_visibility_error'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Asymmetric visibility/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        // Extract property info from error message
        preg_match('/(?:Property |error for )(.*?)::\$(\w+)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        // For now, we'll use a simple string replacement to add asymmetric visibility
        // PHP-Parser v5 doesn't have built-in support for asymmetric visibility yet
        $lines = explode("\n", $content);
        $modifiedLines = [];
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;
            // Check if this is near the error line
            if (abs($lineNumber - $error->getLine()) <= 2) {
                // Look for the property declaration
                if (preg_match('/^\s*(public|protected|private)\s+((?:readonly\s+)?(?:\??\w+(?:\|[\w\\\\]+)*\s+)?)\$' . preg_quote($propertyName, '/') . '\b/', $line, $propertyMatch)) {
                    // Replace with asymmetric visibility
                    $visibility = $propertyMatch[1];
                    $typeAndModifiers = $propertyMatch[2];
                    $newLine = preg_replace(
                        '/^\s*(public|protected|private)\s+/',
                        '    ' . $visibility . ' private(set) ',
                        $line
                    );
                    $modifiedLines[] = $newLine;
                    continue;
                }
            }
            $modifiedLines[] = $line;
        }

        return implode("\n", $modifiedLines);
    }
} 