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
                
                // Convert isset($var) ? $var : $fallback to $var ?? $fallback
                if ($node instanceof Node\Expr\Ternary
                    && $node->cond instanceof Node\Expr\Isset_
                    && $node->if !== null
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    $issetVar = $node->cond->vars[0] ?? null;
                    if ($issetVar !== null && $this->nodesAreEqual($issetVar, $node->if)) {
                        return new Node\Expr\BinaryOp\Coalesce($issetVar, $node->else);
                    }
                }
                
                return null;
            }
            
            private function nodesAreEqual(Node $node1, Node $node2): bool
            {
                // Simple comparison - could be more sophisticated
                if (get_class($node1) !== get_class($node2)) {
                    return false;
                }
                
                if ($node1 instanceof Node\Expr\Variable && $node2 instanceof Node\Expr\Variable) {
                    return $node1->name === $node2->name;
                }
                
                if ($node1 instanceof Node\Expr\ArrayDimFetch && $node2 instanceof Node\Expr\ArrayDimFetch) {
                    return $this->nodesAreEqual($node1->var, $node2->var) && 
                           $this->nodesAreEqual($node1->dim, $node2->dim);
                }
                
                if ($node1 instanceof Node\Expr\PropertyFetch && $node2 instanceof Node\Expr\PropertyFetch) {
                    return $this->nodesAreEqual($node1->var, $node2->var) && 
                           $node1->name->toString() === $node2->name->toString();
                }
                
                if ($node1 instanceof Node\Scalar\String_ && $node2 instanceof Node\Scalar\String_) {
                    return $node1->value === $node2->value;
                }
                
                return false;
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }
}