<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PHPStanFixer\ValueObjects\Error;

class MissingIterableValueTypeFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['missingType.iterableValue'];
    }

    public function canFix(Error $error): bool
    {
        return strpos($error->getMessage(), 'Missing type hint for iterable value') !== false;
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Find the node at the error line
        $targetNode = $this->nodeFinder->findFirst($stmts, function(Node $node) use ($error) {
            return $node->getLine() === $error->getLine();
        });

        if (!$targetNode) {
            return $content;
        }

        // Simple inference: assume array of strings for demo
        // In real impl, analyze the array contents

        // For example, add : array&lt;string&gt; or similar in PHPDoc

        // But since it's type hint, perhaps it's for param or return

        // Assuming it's a property or param without type

        // For simplicity, let's add mixed

        // Better: traverse and add type

        // ... existing code ...

        return $this->printCode($stmts);
    }
} 