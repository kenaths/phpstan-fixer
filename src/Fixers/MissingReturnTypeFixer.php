<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeTraverser;
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
        $methodName = $matches[2] ?? '';

        $visitor = new class($methodName, $error->getLine()) extends NodeVisitorAbstract {
            private string $methodName;
            private int $targetLine;

            public ?array $fix = null;
            public ?array $docFix = null;

            public function __construct(string $methodName, int $targetLine)
            {
                $this->methodName = $methodName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\ClassMethod 
                    && $node->name->toString() === $this->methodName
                    && $node->getLine() <= $this->targetLine) {
                    
                    // Try to infer return type from return statements
                    $inferredType = $this->inferReturnType($node);
                    $arrayDetails = $this->analyzeArrayReturnType($node);
                    
                    if ($node->returnType === null) {
                        $insertionStart = $node->name->getAttribute('endFilePos') + 1;
                        if (!empty($node->params)) {
                            $lastParam = end($node->params);
                            $insertionStart = $lastParam->getAttribute('endFilePos') + 1;
                        }
                        $this->fix = ['insertion_start' => $insertionStart, 'type' => $inferredType];
                    }
                    
                    // If we have array details, add PHPDoc
                    if ($arrayDetails !== null && ($inferredType === 'array' || ($node->returnType && $node->returnType->toString() === 'array'))) {
                        $this->docFix = $this->createDocBlockFix($node, $arrayDetails);
                    }
                }
                
                return null;
            }

            private function inferReturnType(Node\Stmt\ClassMethod $method): string
            {
                $returns = $this->findReturnStatements($method);
                
                if (empty($returns)) {
                    return 'void';
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
                    return 'never';
                }

                // Remove duplicates
                $types = array_unique($types);

                // Handle void returns
                if (in_array('void', $types)) {
                    if (count($types) === 1) {
                        return 'void';
                    }
                    // void cannot be part of a union type
                    $types = array_diff($types, ['void']);
                }

                // No types found, default to mixed
                if (empty($types)) {
                    return $hasNull ? '?mixed' : 'mixed';
                }

                // Single type
                if (count($types) === 1) {
                    $type = reset($types);
                    return $hasNull ? '?' . $type : $type;
                }

                // Multiple types - create union
                sort($types);
                $typeStr = implode('|', $types);
                if ($hasNull) {
                    $typeStr .= '|null';
                }

                return $typeStr;
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

            private function inferVariableType(Node\Expr\Variable $expr): string
            {
                // For $this, return self
                if ($expr->name === 'this') {
                    return 'self';
                }
                return 'mixed';
            }

            private function inferFunctionReturnType(Node\Expr\FuncCall $expr): string
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

            private function inferNewType(Node\Expr\New_ $expr): string
            {
                if ($expr->class instanceof Node\Name) {
                    return $expr->class->toString();
                }
                return 'object';
            }

            private function inferMatchType(Node\Expr\Match_ $expr): string
            {
                $types = [];
                foreach ($expr->arms as $arm) {
                    $type = $this->inferTypeFromExpression($arm->body);
                    if ($type !== null) {
                        $types[] = $type;
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
                $traverser = new NodeTraverser();
                $visitor = new class() extends NodeVisitorAbstract {
                    public array $returnNodes = [];

                    public function enterNode(Node $node): ?int
                    {
                        if ($node instanceof Node\Expr\Closure ||
                            $node instanceof Node\Expr\ArrowFunction ||
                            $node instanceof Node\Stmt\Function_ ||
                            $node instanceof Node\Stmt\Class_
                        ) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }

                        return null;
                    }

                    public function leaveNode(Node $node): ?Node
                    {
                        if ($node instanceof Node\Stmt\Return_) {
                            $this->returnNodes[] = $node;
                        }

                        return null;
                    }
                };

                $traverser->addVisitor($visitor);

                if (($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) && !empty($node->stmts)) {
                    $traverser->traverse($node->stmts);
                }

                return $visitor->returnNodes;
            }

            /**
             * Analyze array return type to get specific key/value types
             */
            private function analyzeArrayReturnType(Node\Stmt\ClassMethod $method): ?array
            {
                $returns = $this->findReturnStatements($method);
                
                $keyTypes = [];
                $valueTypes = [];
                $hasArrayReturn = false;

                foreach ($returns as $return) {
                    if ($return->expr instanceof Node\Expr\Array_) {
                        $hasArrayReturn = true;
                        $this->analyzeArrayExpression($return->expr, $keyTypes, $valueTypes);
                    } elseif ($return->expr instanceof Node\Expr\Variable) {
                        // Try to trace the variable
                        $varName = $return->expr->name;
                        if (is_string($varName)) {
                            $arrayInfo = $this->findVariableArrayAssignment($method, $varName);
                            if ($arrayInfo !== null) {
                                $hasArrayReturn = true;
                                $this->analyzeArrayExpression($arrayInfo, $keyTypes, $valueTypes);
                            }
                        }
                    }
                }

                if (!$hasArrayReturn) {
                    return null;
                }

                $keyType = $this->determineArrayType($keyTypes, 'int');
                $valueType = $this->determineArrayType($valueTypes, 'mixed');

                return ['key' => $keyType, 'value' => $valueType];
            }

            /**
             * Analyze array expression to extract key and value types
             */
            private function analyzeArrayExpression(Node\Expr\Array_ $array, array &$keyTypes, array &$valueTypes): void
            {
                foreach ($array->items as $item) {
                    // Analyze key
                    if ($item->key !== null) {
                        $keyType = $this->inferTypeFromExpression($item->key);
                        if ($keyType !== null && $keyType !== 'null') {
                            $keyTypes[] = $keyType;
                        }
                    } else {
                        $keyTypes[] = 'int';
                    }

                    // Analyze value
                    $valueType = $this->inferTypeFromExpression($item->value);
                    if ($valueType !== null && $valueType !== 'null') {
                        $valueTypes[] = $valueType;
                    }
                }
            }

            /**
             * Find array assignment to a variable
             */
            private function findVariableArrayAssignment(Node $scope, string $varName): ?Node\Expr\Array_
            {
                $assignment = null;
                
                $this->traverseNode($scope, function (Node $node) use ($varName, &$assignment) {
                    if ($node instanceof Node\Expr\Assign 
                        && $node->var instanceof Node\Expr\Variable
                        && $node->var->name === $varName
                        && $node->expr instanceof Node\Expr\Array_) {
                        $assignment = $node->expr;
                    }
                });
                
                return $assignment;
            }

            /**
             * Traverse a node with a callback
             */
            private function traverseNode(Node $node, callable $callback): void
            {
                $callback($node);
                
                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    if ($subNode instanceof Node) {
                        $this->traverseNode($subNode, $callback);
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node) {
                                $this->traverseNode($child, $callback);
                            }
                        }
                    }
                }
            }

            /**
             * Determine the most specific array type
             */
            private function determineArrayType(array $types, string $default = 'mixed'): string
            {
                if (empty($types)) {
                    return $default;
                }

                $uniqueTypes = array_unique($types);
                if (count($uniqueTypes) === 1) {
                    return reset($uniqueTypes);
                }

                // If we have multiple types, create a union (up to 3 types)
                $typeCounts = array_count_values($types);
                arsort($typeCounts);
                $topTypes = array_slice(array_keys($typeCounts), 0, 3);
                
                return implode('|', $topTypes);
            }

            /**
             * Create a docblock fix for array return type
             */
            private function createDocBlockFix(Node\Stmt\ClassMethod $method, array $arrayDetails): array
            {
                $existingDoc = $method->getDocComment();
                $docText = $existingDoc ? $existingDoc->getText() : '';
                
                $keyType = $arrayDetails['key'];
                $valueType = $arrayDetails['value'];
                
                // Format array type
                $arrayType = $keyType === 'int' ? "array<{$valueType}>" : "array<{$keyType}, {$valueType}>";
                
                if (!empty($docText)) {
                    // Check if @return already exists
                    if (preg_match('/@return\s+array(?!\<)/', $docText)) {
                        // Update existing @return
                        $docText = preg_replace(
                            '/@return\s+array(?!\<)/',
                            '@return ' . $arrayType,
                            $docText
                        );
                    } else if (!preg_match('/@return/', $docText)) {
                        // Add @return before closing */
                        $docText = str_replace('*/', "* @return {$arrayType}\n */", $docText);
                    }
                } else {
                    // Create new docblock
                    $indent = str_repeat(' ', max(0, $method->getAttribute('startColumn', 4) - 1));
                    $docText = "/**\n{$indent} * @return {$arrayType}\n{$indent} */";
                }
                
                if ($existingDoc) {
                    return [
                        'start' => $existingDoc->getStartFilePos(),
                        'end' => $existingDoc->getEndFilePos() + 1,
                        'text' => $docText
                    ];
                } else {
                    return [
                        'start' => $method->getAttribute('startFilePos'),
                        'end' => $method->getAttribute('startFilePos'),
                        'text' => $docText . "\n" . str_repeat(' ', max(0, $method->getAttribute('startColumn', 4) - 1))
                    ];
                }
            }
        };

        $this->traverseWithVisitor($stmts, $visitor);
        
        // Apply docblock fix first if needed
        if ($visitor->docFix !== null) {
            $content = substr($content, 0, $visitor->docFix['start']) . 
                      $visitor->docFix['text'] . 
                      substr($content, $visitor->docFix['end']);
        }
        
        // Apply return type fix
        if ($visitor->fix !== null) {
            $start = $visitor->fix['insertion_start'];
            $parenPos = strpos($content, ')', $start);
            if ($parenPos !== false) {
                $insertPos = $parenPos + 1;
                while (isset($content[$insertPos]) && ctype_space($content[$insertPos])) {
                    $insertPos++;
                }
                $text = ': ' . $visitor->fix['type'];
                $content = substr($content, 0, $insertPos) . $text . substr($content, $insertPos);
            }
        }
        
        return $content;
    }
}