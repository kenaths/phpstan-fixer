<?php

declare(strict_types=1);

namespace PHPStanFixer\Validation;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Validates type consistency across properties, methods, and assignments
 */
class TypeConsistencyValidator
{
    /**
     * @return array<string> Array of validation errors
     */
    public function validateCode(string $content): array
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return ['Failed to parse code for validation'];
        }

        $validator = new class() extends NodeVisitorAbstract {
            public array $validationErrors = [];
            private array $properties = [];
            private array $methods = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->validateClass($node);
                }
                
                return null;
            }

            private function validateClass(Node\Stmt\Class_ $class): void
            {
                // Collect properties and methods
                $this->properties = [];
                $this->methods = [];
                
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Property) {
                        foreach ($stmt->props as $prop) {
                            $this->properties[$prop->name->toString()] = [
                                'node' => $stmt,
                                'type' => $stmt->type
                            ];
                        }
                    } elseif ($stmt instanceof Node\Stmt\ClassMethod) {
                        $this->methods[$stmt->name->toString()] = $stmt;
                    }
                }
                
                // Validate type consistency patterns
                $this->validateCallbackPattern();
                $this->validatePropertyAssignments();
            }

            private function validateCallbackPattern(): void
            {
                // Check for callback property + usingQuery method pattern
                if (isset($this->properties['callback']) && isset($this->methods['usingQuery'])) {
                    $callbackProperty = $this->properties['callback'];
                    $usingQueryMethod = $this->methods['usingQuery'];
                    
                    $propertyType = $this->getTypeString($callbackProperty['type']);
                    $methodParamType = $this->getMethodFirstParamType($usingQueryMethod);
                    
                    if ($propertyType && $methodParamType) {
                        if (!$this->areTypesCompatible($propertyType, $methodParamType)) {
                            $this->validationErrors[] = sprintf(
                                'Type mismatch: Property $callback is %s but usingQuery() accepts %s',
                                $propertyType,
                                $methodParamType
                            );
                        }
                    }
                }
            }

            private function validatePropertyAssignments(): void
            {
                // Look for direct property assignments that might cause type mismatches
                foreach ($this->methods as $methodName => $method) {
                    if ($method->stmts) {
                        $this->validateMethodAssignments($method);
                    }
                }
            }

            private function validateMethodAssignments(Node\Stmt\ClassMethod $method): void
            {
                foreach ($method->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Expression && 
                        $stmt->expr instanceof Node\Expr\Assign) {
                        $this->validateAssignment($stmt->expr);
                    }
                }
            }

            private function validateAssignment(Node\Expr\Assign $assign): void
            {
                // Check property assignments like $this->callback = $callback;
                if ($assign->var instanceof Node\Expr\PropertyFetch &&
                    $assign->var->var instanceof Node\Expr\Variable &&
                    $assign->var->var->name === 'this') {
                    
                    $propertyName = $assign->var->name instanceof Node\Identifier 
                        ? $assign->var->name->toString() 
                        : null;
                    
                    if ($propertyName && isset($this->properties[$propertyName])) {
                        $propertyType = $this->getTypeString($this->properties[$propertyName]['type']);
                        $assignedType = $this->inferExpressionType($assign->expr);
                        
                        if ($propertyType && $assignedType && 
                            !$this->areTypesCompatible($propertyType, $assignedType)) {
                            $this->validationErrors[] = sprintf(
                                'Assignment type mismatch: Property $%s expects %s but assigned %s',
                                $propertyName,
                                $propertyType,
                                $assignedType
                            );
                        }
                    }
                }
            }

            private function getTypeString(?Node $typeNode): ?string
            {
                if ($typeNode === null) {
                    return null;
                }
                
                if ($typeNode instanceof Node\Name) {
                    return $typeNode->toString();
                } elseif ($typeNode instanceof Node\NullableType) {
                    $innerType = $this->getTypeString($typeNode->type);
                    return $innerType ? '?' . $innerType : null;
                } elseif ($typeNode instanceof Node\UnionType) {
                    $types = [];
                    foreach ($typeNode->types as $type) {
                        $typeStr = $this->getTypeString($type);
                        if ($typeStr) {
                            $types[] = $typeStr;
                        }
                    }
                    return implode('|', $types);
                }
                
                return null;
            }

            private function getMethodFirstParamType(Node\Stmt\ClassMethod $method): ?string
            {
                if (!empty($method->params)) {
                    return $this->getTypeString($method->params[0]->type);
                }
                
                return null;
            }

            private function inferExpressionType(Node\Expr $expr): ?string
            {
                if ($expr instanceof Node\Expr\Variable) {
                    // For now, return 'mixed' for variables
                    // In a full implementation, we'd track variable types
                    return 'mixed';
                } elseif ($expr instanceof Node\Scalar\String_) {
                    return 'string';
                } elseif ($expr instanceof Node\Scalar\Int_) {
                    return 'int';
                } elseif ($expr instanceof Node\Scalar\Float_) {
                    return 'float';
                }
                
                return null;
            }

            private function areTypesCompatible(string $expectedType, string $actualType): bool
            {
                // Remove nullable markers for comparison
                $expectedClean = str_replace('?', '', $expectedType);
                $actualClean = str_replace('?', '', $actualType);
                
                // Exact match
                if ($expectedClean === $actualClean) {
                    return true;
                }
                
                // Check union types
                if (str_contains($expectedType, '|')) {
                    $expectedTypes = explode('|', $expectedType);
                    return in_array($actualClean, $expectedTypes) || 
                           in_array($actualType, $expectedTypes);
                }
                
                // Special compatibility rules
                $compatibilityMap = [
                    'mixed' => ['string', 'int', 'float', 'bool', 'array', 'object'],
                    'callable' => ['string', 'Closure'],
                    'iterable' => ['array'],
                ];
                
                if (isset($compatibilityMap[$expectedClean]) && 
                    in_array($actualClean, $compatibilityMap[$expectedClean])) {
                    return true;
                }
                
                return false;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($validator);
        $traverser->traverse($stmts);
        
        return $validator->validationErrors;
    }

    private function parseCode(string $content): ?array
    {
        try {
            $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
            return $parser->parse($content);
        } catch (\PhpParser\Error $e) {
            return null;
        }
    }
}