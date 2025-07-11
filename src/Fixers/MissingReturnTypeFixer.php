<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes missing return type declarations with PHP 8.4 support
 */
class MissingReturnTypeFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['missing_return_type', 'incompatible_return_type'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Method .* has no return type specified|Method .* should return/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract method name from error message
        preg_match('/Method (.*?)::(\w+)\(\)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $methodName = $matches[2] ?? '';

        $visitor = new class($methodName, $error->getLine()) extends NodeVisitorAbstract {
            private string $methodName;
            private int $targetLine;

            public function __construct(string $methodName, int $targetLine)
            {
                $this->methodName = $methodName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod 
                    && $node->name->toString() === $this->methodName
                    && $node->getLine() <= $this->targetLine
                    && $node->returnType === null) {
                    
                    // Try to infer return type from return statements
                    $returnType = $this->inferReturnType($node);
                    if ($returnType !== null) {
                        $node->returnType = $returnType;
                    }
                }
                
                return null;
            }

            private function inferReturnType(Node\Stmt\ClassMethod $method): ?Node
            {
                $returns = $this->findReturnStatements($method);
                
                if (empty($returns)) {
                    return new Node\Name('void');
                }

                $types = [];
                $hasNull = false;
                $hasNever = false;

                // Analyze all return statements
                foreach ($returns as $return) {
                    if ($return->expr === null) {
                        $types[] = 'void';
                        continue;
                    }
                    
                    $type = $this->inferTypeFromExpression($return->expr);
                    if ($type !== null) {
                        if ($type === 'null') {
                            $hasNull = true;
                        } elseif ($type === 'never') {
                            $hasNever = true;
                        } else {
                            $types[] = $type;
                        }
                    }
                }

                // If method always throws or exits, return never
                if ($hasNever && empty($types)) {
                    return new Node\Name('never');
                }

                // Remove duplicates
                $types = array_unique($types);

                // Handle void returns
                if (in_array('void', $types)) {
                    if (count($types) === 1) {
                        return new Node\Name('void');
                    }
                    // void cannot be part of a union type
                    $types = array_diff($types, ['void']);
                }

                // No types found, default to mixed
                if (empty($types)) {
                    return $hasNull ? new Node\Name('?mixed') : new Node\Name('mixed');
                }

                // Single type
                if (count($types) === 1) {
                    $type = reset($types);
                    if ($hasNull) {
                        return new Node\NullableType(new Node\Name($type));
                    }
                    return new Node\Name($type);
                }

                // Multiple types - create union
                $nodeTypes = [];
                foreach ($types as $type) {
                    $nodeTypes[] = new Node\Name($type);
                }
                
                if ($hasNull) {
                    $nodeTypes[] = new Node\Name('null');
                }

                return new Node\UnionType($nodeTypes);
            }

            private function inferTypeFromExpression(Node $expr): ?string
            {
                return match (true) {
                    $expr instanceof Node\Scalar\String_ => 'string',
                    $expr instanceof Node\Scalar\LNumber => 'int',
                    $expr instanceof Node\Scalar\DNumber => 'float',
                    $expr instanceof Node\Expr\Array_ => 'array',
                    $expr instanceof Node\Expr\ConstFetch => $this->inferTypeFromConstant($expr),
                    $expr instanceof Node\Expr\BooleanNot,
                    $expr instanceof Node\Expr\BinaryOp\BooleanAnd,
                    $expr instanceof Node\Expr\BinaryOp\BooleanOr,
                    $expr instanceof Node\Expr\BinaryOp\Greater,
                    $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual,
                    $expr instanceof Node\Expr\BinaryOp\Smaller,
                    $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual,
                    $expr instanceof Node\Expr\BinaryOp\Equal,
                    $expr instanceof Node\Expr\BinaryOp\NotEqual,
                    $expr instanceof Node\Expr\BinaryOp\Identical,
                    $expr instanceof Node\Expr\BinaryOp\NotIdentical => 'bool',
                    $expr instanceof Node\Expr\Variable => $this->inferVariableType($expr),
                    $expr instanceof Node\Expr\PropertyFetch => 'mixed',
                    $expr instanceof Node\Expr\MethodCall => 'mixed',
                    $expr instanceof Node\Expr\FuncCall => $this->inferFunctionReturnType($expr),
                    $expr instanceof Node\Expr\New_ => $this->inferNewType($expr),
                    $expr instanceof Node\Expr\Throw_ => 'never',
                    $expr instanceof Node\Expr\Exit_ => 'never',
                    $expr instanceof Node\Expr\Match_ => $this->inferMatchType($expr),
                    default => null,
                };
            }

            private function inferTypeFromConstant(Node\Expr\ConstFetch $expr): ?string
            {
                $name = $expr->name->toLowerString();
                return match ($name) {
                    'true', 'false' => 'bool',
                    'null' => 'null',
                    default => null,
                };
            }

            private function inferVariableType(Node\Expr\Variable $expr): ?string
            {
                // For $this, return self
                if ($expr->name === 'this') {
                    return 'self';
                }
                return 'mixed';
            }

            private function inferFunctionReturnType(Node\Expr\FuncCall $expr): ?string
            {
                if ($expr->name instanceof Node\Name) {
                    $funcName = $expr->name->toLowerString();
                    return match ($funcName) {
                        'count', 'strlen', 'strpos', 'time' => 'int',
                        'explode', 'array_merge', 'array_map' => 'array',
                        'implode', 'trim', 'strtolower', 'strtoupper' => 'string',
                        'is_null', 'is_string', 'is_array', 'is_int' => 'bool',
                        default => 'mixed',
                    };
                }
                return 'mixed';
            }

            private function inferNewType(Node\Expr\New_ $expr): ?string
            {
                if ($expr->class instanceof Node\Name) {
                    return $expr->class->toString();
                }
                return 'object';
            }

            private function inferMatchType(Node\Expr\Match_ $expr): ?string
            {
                $types = [];
                foreach ($expr->arms as $arm) {
                    if ($arm->body !== null) {
                        $type = $this->inferTypeFromExpression($arm->body);
                        if ($type !== null) {
                            $types[] = $type;
                        }
                    }
                }
                
                $types = array_unique($types);
                return count($types) === 1 ? reset($types) : 'mixed';
            }

            /**
             * @return array<Node\Stmt\Return_>
             */
            private function findReturnStatements(Node $node): array
            {
                $returns = [];
                
                if ($node instanceof Node\Stmt\Return_) {
                    $returns[] = $node;
                }
                
                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    
                    if ($subNode instanceof Node) {
                        $returns = array_merge($returns, $this->findReturnStatements($subNode));
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node) {
                                $returns = array_merge($returns, $this->findReturnStatements($child));
                            }
                        }
                    }
                }
                
                return $returns;
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}