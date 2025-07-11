<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class DocBlockFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['invalid_phpdoc'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/PHPDoc tag @/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        // For PHPDoc fixes, we'll do simple regex replacements
        // This is a simplified approach - a full implementation would parse PHPDoc properly
        
        $lines = explode("\n", $content);
        $targetLine = $error->getLine() - 1;
        
        if (!isset($lines[$targetLine])) {
            return $content;
        }

        // Common PHPDoc fixes
        if (preg_match('/@param\s+(\w+)\s+\$(\w+)/', $lines[$targetLine], $matches)) {
            // Fix parameter order: @param type $name
            $lines[$targetLine] = preg_replace(
                '/@param\s+(\w+)\s+\$(\w+)/',
                '@param $1 $$2',
                $lines[$targetLine]
            );
        }

        if (preg_match('/@return\s+$/', $lines[$targetLine])) {
            // Add return type
            $lines[$targetLine] = str_replace('@return', '@return mixed', $lines[$targetLine]);
        }

        return implode("\n", $lines);
    }
}