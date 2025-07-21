<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Converts traditional constructor properties to promoted properties (PHP 8.0+)
 */
class ConstructorPromotionFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['constructor_promotion_error', 'promoted_property_type'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Constructor property promotion|Promoted property/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($error->getLine()) extends NodeVisitorAbstract {
            private int $targetLine;
            /** @var array<string> */
            private array $propertiesToPromote = [];
            private ?Node\Stmt\Class_ $currentClass = null;

            public function __construct(int $targetLine)
            {
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->currentClass = $node;
                    $this->analyzeClassForPromotion($node);
                }

                if ($node instanceof Node\Stmt\ClassMethod 
                    && $node->name->toString() === '__construct'
                    && $this->currentClass !== null
                    && abs($node->getLine() - $this->targetLine) < 10) {
                    
                    $this->promoteConstructorProperties($node);
                }
                
                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    // Remove promoted properties from class body
                    $node->stmts = array_filter($node->stmts, function ($stmt) {
                        if ($stmt instanceof Node\Stmt\Property) {
                            foreach ($stmt->props as $prop) {
                                if (in_array($prop->name->toString(), $this->propertiesToPromote)) {
                                    return false;
                                }
                            }
                        }
                        return true;
                    });
                    
                    $this->currentClass = null;
                    $this->propertiesToPromote = [];
                }
                
                return null;
            }

            private function analyzeClassForPromotion(Node\Stmt\Class_ $class): void
            {
                $constructor = null;
                $properties = [];

                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === '__construct') {
                        $constructor = $stmt;
                    } elseif ($stmt instanceof Node\Stmt\Property) {
                        foreach ($stmt->props as $prop) {
                            $properties[$prop->name->toString()] = $stmt;
                        }
                    }
                }

                if ($constructor === null) {
                    return;
                }

                // Check which properties can be promoted
                foreach ($constructor->stmts ?? [] as $stmt) {
                    if ($stmt instanceof Node\Stmt\Expression 
                        && $stmt->expr instanceof Node\Expr\Assign
                        && $stmt->expr->var instanceof Node\Expr\PropertyFetch
                        && $stmt->expr->var->var instanceof Node\Expr\Variable
                        && $stmt->expr->var->var->name === 'this'
                        && $stmt->expr->expr instanceof Node\Expr\Variable) {
                        
                        $propertyName = $stmt->expr->var->name->toString();
                        $paramName = $stmt->expr->expr->name;

                        // Check if parameter exists and property exists
                        foreach ($constructor->params as $param) {
                            if ($param->var->name === $paramName && isset($properties[$propertyName])) {
                                $this->propertiesToPromote[] = $propertyName;
                            }
                        }
                    }
                }
            }

            private function promoteConstructorProperties(Node\Stmt\ClassMethod $constructor): void
            {
                $promotedParams = [];
                $remainingStmts = [];

                foreach ($constructor->params as $param) {
                    $promoted = false;

                    foreach ($constructor->stmts ?? [] as $stmt) {
                        if ($this->isPropertyAssignment($stmt, $param->var->name)) {
                            $propertyName = $this->getPropertyNameFromAssignment($stmt);
                            
                            if (in_array($propertyName, $this->propertiesToPromote)) {
                                // Promote this parameter
                                $property = $this->findProperty($propertyName);
                                if ($property !== null) {
                                    $param->flags = $property->flags;
                                    $param->type = $param->type ?? $property->type ?? new Node\Name('mixed');
                                    $promoted = true;
                                }
                            }
                        }
                    }

                    $promotedParams[] = $param;
                }

                // Remove assignment statements for promoted properties
                foreach ($constructor->stmts ?? [] as $stmt) {
                    if (!$this->isPromotedPropertyAssignment($stmt)) {
                        $remainingStmts[] = $stmt;
                    }
                }

                $constructor->params = $promotedParams;
                $constructor->stmts = $remainingStmts;
            }

            private function isPropertyAssignment(Node $stmt, string $paramName): bool
            {
                return $stmt instanceof Node\Stmt\Expression 
                    && $stmt->expr instanceof Node\Expr\Assign
                    && $stmt->expr->var instanceof Node\Expr\PropertyFetch
                    && $stmt->expr->var->var instanceof Node\Expr\Variable
                    && $stmt->expr->var->var->name === 'this'
                    && $stmt->expr->expr instanceof Node\Expr\Variable
                    && $stmt->expr->expr->name === $paramName;
            }

            private function getPropertyNameFromAssignment(Node $stmt): ?string
            {
                if ($stmt instanceof Node\Stmt\Expression 
                    && $stmt->expr instanceof Node\Expr\Assign
                    && $stmt->expr->var instanceof Node\Expr\PropertyFetch) {
                    return $stmt->expr->var->name->toString();
                }
                return null;
            }

            private function isPromotedPropertyAssignment(Node $stmt): bool
            {
                $propertyName = $this->getPropertyNameFromAssignment($stmt);
                return $propertyName !== null && in_array($propertyName, $this->propertiesToPromote);
            }

            private function findProperty(string $name): ?Node\Stmt\Property
            {
                if ($this->currentClass === null) {
                    return null;
                }

                foreach ($this->currentClass->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Property) {
                        foreach ($stmt->props as $prop) {
                            if ($prop->name->toString() === $name) {
                                return $stmt;
                            }
                        }
                    }
                }

                return null;
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }
}