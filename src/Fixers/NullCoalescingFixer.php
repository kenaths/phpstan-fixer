<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class NullCoalescingFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['null_coalescing'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/isset\(\) construct can be replaced with null coalesce operator/', $error->getMessage());
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
                // Convert isset() ?: to ??
                if ($node instanceof Node\Expr\Ternary
                    && $node->cond instanceof Node\Expr\Isset_
                    && $node->if === null
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    $var = $node->cond->vars[0] ?? null;
                    if ($var !== null) {
                        return new Node\Expr\BinaryOp\Coalesce($var, $node->else);
                    }
                }
                
                return null;
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}