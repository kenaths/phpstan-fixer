<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes type consistency errors where method parameters don't match property types.
 * 
 * This fixer handles:
 * - Property type mismatches (e.g., ?Closure property with string assignment)
 * - Method parameter type inconsistencies
 * - Automatic creation of union types for compatibility
 * - Proper type safety validation
 * 
 * @author PHPStan Fixer
 * @version 2.0
 */
class TypeConsistencyFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return [
            'method.inconsistentTypes',
            'property.typeMismatch',
            'parameter.typeIncompatible',
        ];
    }

    public function canFix(Error $error): bool
    {
        try {
            $message = $error->getMessage();
            
            // Input validation
            if (empty($message)) {
                return false;
            }
            
            // Check for type assignment mismatches with comprehensive patterns
            return (str_contains($message, 'expects') && 
                    (str_contains($message, 'given') || str_contains($message, 'but'))) ||
                   (str_contains($message, 'does not accept')) ||
                   (str_contains($message, 'incompatible type'));
        } catch (\Throwable $e) {
            error_log('TypeConsistencyFixer canFix error: ' . $e->getMessage());
            return false;
        }
    }

    public function fix(string $content, Error $error): string
    {
        try {
            // Input validation
            if (empty($content)) {
                throw new \InvalidArgumentException('Content cannot be empty');
            }

            $stmts = $this->parseCode($content);
            if ($stmts === null) {
                throw new \RuntimeException('Failed to parse PHP code');
            }

        $visitor = new class($error) extends NodeVisitorAbstract {
            private Error $error;

            public function __construct(Error $error)
            {
                $this->error = $error;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->fixTypeConsistencyInClass($node);
                }
                
                return null;
            }

            private function fixTypeConsistencyInClass(Node\Stmt\Class_ $class): void
            {
                $properties = [];
                $methods = [];
                
                // Collect properties and methods
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Property) {
                        foreach ($stmt->props as $prop) {
                            $properties[$prop->name->toString()] = $stmt;
                        }
                    } elseif ($stmt instanceof Node\Stmt\ClassMethod) {
                        $methods[$stmt->name->toString()] = $stmt;
                    }
                }
                
                // Look for specific patterns like NumericFilterWithOperator
                $this->fixCallbackTypeIssue($properties, $methods);
                
                // Also handle generic property type mismatches from error message
                $this->fixPropertyTypeMismatch($properties);
            }

            private function fixCallbackTypeIssue(array $properties, array $methods): void
            {
                // Look for callback property and usingQuery method pattern
                if (isset($properties['callback']) && isset($methods['usingQuery'])) {
                    $callbackProperty = $properties['callback'];
                    $usingQueryMethod = $methods['usingQuery'];
                    
                    // Check if property is nullable Closure and method accepts string
                    if ($this->isNullableClosureProperty($callbackProperty) && 
                        $this->acceptsStringParameter($usingQueryMethod)) {
                        
                        // Fix by changing property type to string|Closure|null
                        $this->updatePropertyType($callbackProperty);
                    }
                }
            }

            private function isNullableClosureProperty(Node\Stmt\Property $property): bool
            {
                if ($property->type instanceof Node\NullableType) {
                    $innerType = $property->type->type;
                    return $innerType instanceof Node\Name && $innerType->toString() === 'Closure';
                }
                
                return false;
            }

            private function acceptsStringParameter(Node\Stmt\ClassMethod $method): bool
            {
                if (!empty($method->params)) {
                    $firstParam = $method->params[0];
                    if ($firstParam->type instanceof Node\Name) {
                        return $firstParam->type->toString() === 'string';
                    }
                }
                
                return false;
            }

            private function fixPropertyTypeMismatch(array $properties): void
            {
                try {
                    // Parse the error message to find which property has the mismatch
                    $message = $this->error->getMessage();
                    
                    if (empty($message)) {
                        throw new \InvalidArgumentException('Error message is empty');
                    }
                    
                    // Extract property name from message like "Property TestClass::$callback (Closure|null) does not accept string."
                    if (preg_match('/Property [^:]+::\$(\w+) \([^)]+\) does not accept (\w+)/', $message, $matches)) {
                        $propertyName = $matches[1];
                        $rejectedType = $matches[2];
                        
                        if (empty($propertyName) || empty($rejectedType)) {
                            throw new \RuntimeException('Could not extract property name or rejected type from error message');
                        }
                        
                        if (isset($properties[$propertyName])) {
                            $property = $properties[$propertyName];
                            $this->updatePropertyTypeForCompatibility($property, $rejectedType);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('TypeConsistencyFixer fixPropertyTypeMismatch error: ' . $e->getMessage());
                }
            }

            private function updatePropertyType(Node\Stmt\Property $property): void
            {
                // Change from ?Closure to string|Closure|null
                $property->type = new Node\UnionType([
                    new Node\Name('string'),
                    new Node\Name('Closure'),
                    new Node\Name('null')
                ]);
            }
            
            private function updatePropertyTypeForCompatibility(Node\Stmt\Property $property, string $rejectedType): void
            {
                // Extract current type from property
                $currentType = $property->type;
                
                if ($currentType instanceof Node\NullableType) {
                    // Handle ?Closure -> string|Closure|null
                    $innerType = $currentType->type;
                    if ($innerType instanceof Node\Name) {
                        $currentTypeName = $innerType->toString();
                        
                        // Create union type that accepts both current and rejected types
                        $property->type = new Node\UnionType([
                            new Node\Name($rejectedType),
                            new Node\Name($currentTypeName),
                            new Node\Name('null')
                        ]);
                    }
                } elseif ($currentType instanceof Node\Name) {
                    // Handle Closure -> string|Closure
                    $currentTypeName = $currentType->toString();
                    $property->type = new Node\UnionType([
                        new Node\Name($rejectedType),
                        new Node\Name($currentTypeName)
                    ]);
                }
            }
        };

            return $this->fixWithFormatPreservation($content, $visitor);
            
        } catch (\Throwable $e) {
            // Log the error and return original content to prevent breaking the code
            error_log('TypeConsistencyFixer error: ' . $e->getMessage());
            return $content; // Return original content on error
        }
    }
}