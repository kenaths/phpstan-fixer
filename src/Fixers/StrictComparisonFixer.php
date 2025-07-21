<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class StrictComparisonFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['strict_comparison'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Strict comparison using === between/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($error->getLine()) extends NodeVisitorAbstract {
            private int $targetLine;

            public function __construct(int $targetLine)
            {
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node): ?Node
            {
                // Convert == to === and != to !==
                if ($node instanceof Node\Expr\BinaryOp\Equal
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    return new Node\Expr\BinaryOp\Identical($node->left, $node->right);
                }
                
                if ($node instanceof Node\Expr\BinaryOp\NotEqual
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    return new Node\Expr\BinaryOp\NotIdentical($node->left, $node->right);
                }
                
                return null;
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }
}