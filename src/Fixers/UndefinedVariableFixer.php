<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Utilities\RegexPatterns;

class UndefinedVariableFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['undefined_variable'];
    }

    public function canFix(Error $error): bool
    {
        // Use optimized string check instead of regex for simple cases
        return str_contains($error->getMessage(), 'Undefined variable');
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract variable name from error message
        preg_match(RegexPatterns::EXTRACT_VARIABLE_NAME, $error->getMessage(), $matches);
        $varName = $matches[1] ?? '';

        $visitor = new class($varName, $error->getLine()) extends NodeVisitorAbstract {
            private string $varName;
            private int $targetLine;
            private bool $initialized = false;

            public function __construct(string $varName, int $targetLine)
            {
                $this->varName = $varName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node): ?Node
            {
                // Initialize variable at the beginning of the function/method
                if (!$this->initialized && $node instanceof Node\Stmt\Function_
                    && $this->containsVariableUsage($node, $this->varName)) {
                    
                    $init = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable($this->varName),
                            new Node\Expr\ConstFetch(new Node\Name('null'))
                        )
                    );
                    
                    array_unshift($node->stmts, $init);
                    $this->initialized = true;
                }
                
                if (!$this->initialized && $node instanceof Node\Stmt\ClassMethod
                    && $this->containsVariableUsage($node, $this->varName)) {
                    
                    $init = new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\Variable($this->varName),
                            new Node\Expr\ConstFetch(new Node\Name('null'))
                        )
                    );
                    
                    if ($node->stmts !== null) {
                        array_unshift($node->stmts, $init);
                    }
                    $this->initialized = true;
                }
                
                return null;
            }

            private function containsVariableUsage(Node $node, string $varName): bool
            {
                if ($node instanceof Node\Expr\Variable && $node->name === $varName) {
                    return true;
                }
                
                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    
                    if ($subNode instanceof Node && $this->containsVariableUsage($subNode, $varName)) {
                        return true;
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node && $this->containsVariableUsage($child, $varName)) {
                                return true;
                            }
                        }
                    }
                }
                
                return false;
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }
}