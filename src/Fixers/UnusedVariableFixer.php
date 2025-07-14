<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class UnusedVariableFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['unused_variable'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Variable .* is never used/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract variable name from error message
        preg_match('/Variable \$(\w+) is never used/', $error->getMessage(), $matches);
        $varName = $matches[1] ?? '';

        $visitor = new class($varName, $error->getLine()) extends NodeVisitorAbstract {
            private string $varName;
            private int $targetLine;

            public function __construct(string $varName, int $targetLine)
            {
                $this->varName = $varName;
                $this->targetLine = $targetLine;
            }

            public function leaveNode(Node $node): int|null
            {
                // Remove assignments to unused variables
                if ($node instanceof Node\Stmt\Expression
                    && $node->expr instanceof Node\Expr\Assign
                    && $node->expr->var instanceof Node\Expr\Variable
                    && $node->expr->var->name === $this->varName
                    && abs($node->getLine() - $this->targetLine) < 5) {
                    
                    // Check if the right side has side effects
                    if (!$this->hasSideEffects($node->expr->expr)) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                }
                
                return null;
            }

            private function hasSideEffects(Node $node): bool
            {
                // Simple check for method/function calls
                if ($node instanceof Node\Expr\FuncCall 
                    || $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall) {
                    return true;
                }
                
                // Check sub-nodes
                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    
                    if ($subNode instanceof Node && $this->hasSideEffects($subNode)) {
                        return true;
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node && $this->hasSideEffects($child)) {
                                return true;
                            }
                        }
                    }
                }
                
                return false;
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}