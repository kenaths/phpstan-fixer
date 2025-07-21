<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

use PhpParser\Node;

/**
 * Enhanced type inference utility that provides smarter type detection
 * based on context, naming patterns, and usage analysis
 */
class TypeInferrer
{
    /**
     * Infer parameter type based on context and naming patterns
     */
    public static function inferParameterType(string $paramName, string $className = '', string $methodName = ''): string
    {
        $paramName = strtolower($paramName);
        
        // Context-aware inference based on method and class names
        $methodName = strtolower($methodName);
        $className = strtolower($className);
        
        // Laravel/Web specific patterns
        if (str_contains($className, 'controller') || str_contains($className, 'request')) {
            if ($paramName === 'request') return 'Illuminate\\Http\\Request';
            if ($paramName === 'response') return 'Illuminate\\Http\\Response';
        }
        
        if (str_contains($className, 'component') || str_contains($className, 'blade')) {
            if ($paramName === 'content' || $paramName === 'data') {
                return 'mixed'; // For now, union types aren't always supported in PHP declarations
            }
        }
        
        // Method-specific patterns
        if (str_contains($methodName, 'set') || str_contains($methodName, 'with')) {
            // Setter methods often return self for chaining
            if ($paramName === 'value' || $paramName === 'data') {
                return 'mixed'; // But let's try to be more specific
            }
        }
        
        // Common parameter patterns
        $patterns = [
            // String patterns
            'name' => 'string', 'title' => 'string', 'content' => 'string',
            'text' => 'string', 'message' => 'string', 'description' => 'string',
            'email' => 'string', 'username' => 'string', 'password' => 'string',
            'slug' => 'string', 'url' => 'string', 'path' => 'string',
            'label' => 'string', 'class' => 'string', 'type' => 'string',
            'status' => 'string', 'state' => 'string', 'action' => 'string',
            
            // Integer patterns  
            'id' => 'int', 'count' => 'int', 'number' => 'int', 'amount' => 'int',
            'quantity' => 'int', 'size' => 'int', 'length' => 'int', 'width' => 'int',
            'height' => 'int', 'position' => 'int', 'index' => 'int', 'order' => 'int',
            'priority' => 'int', 'level' => 'int', 'score' => 'int',
            
            // Float patterns
            'price' => 'float', 'cost' => 'float', 'rate' => 'float', 'percentage' => 'float',
            'ratio' => 'float', 'weight' => 'float', 'distance' => 'float',
            
            // Boolean patterns
            'enabled' => 'bool', 'disabled' => 'bool', 'active' => 'bool', 'visible' => 'bool',
            'hidden' => 'bool', 'public' => 'bool', 'private' => 'bool', 'required' => 'bool',
            'optional' => 'bool', 'readonly' => 'bool', 'checked' => 'bool', 'selected' => 'bool',
            
            // Array patterns
            'options' => 'array', 'items' => 'array', 'list' => 'array', 'data' => 'array',
            'config' => 'array', 'settings' => 'array', 'params' => 'array', 'args' => 'array',
            'attributes' => 'array', 'properties' => 'array', 'meta' => 'array',
            'headers' => 'array', 'cookies' => 'array', 'session' => 'array',
        ];
        
        // Direct name matches
        foreach ($patterns as $pattern => $type) {
            if ($paramName === $pattern) {
                return $type;
            }
        }
        
        // Partial matches with higher confidence
        foreach ($patterns as $pattern => $type) {
            if (str_contains($paramName, $pattern)) {
                return $type;
            }
        }
        
        // Suffix-based inference
        if (str_ends_with($paramName, '_id') || str_ends_with($paramName, 'id')) {
            return 'int';
        }
        if (str_ends_with($paramName, '_name') || str_ends_with($paramName, 'name')) {
            return 'string';
        }
        if (str_ends_with($paramName, '_count') || str_ends_with($paramName, 'count')) {
            return 'int';
        }
        if (str_ends_with($paramName, '_flag') || str_ends_with($paramName, 'flag')) {
            return 'bool';
        }
        if (str_ends_with($paramName, '_data') || str_ends_with($paramName, 'data')) {
            return 'array';
        }
        
        // Prefix-based inference
        if (str_starts_with($paramName, 'is_') || str_starts_with($paramName, 'has_') || str_starts_with($paramName, 'can_')) {
            return 'bool';
        }
        if (str_starts_with($paramName, 'num_') || str_starts_with($paramName, 'total_')) {
            return 'int';
        }
        
        // If we can't infer a specific type, use a more specific fallback than 'mixed'
        return 'string'; // String is often a safer default than mixed
    }
    
    /**
     * Infer property type based on context and naming patterns
     */
    public static function inferPropertyType(string $propertyName, string $className = '', ?Node $defaultValue = null): string
    {
        // If we have a default value, analyze it first
        if ($defaultValue !== null) {
            $valueType = self::inferTypeFromValue($defaultValue);
            if ($valueType !== 'mixed') {
                return $valueType;
            }
        }
        
        $propertyName = strtolower($propertyName);
        $className = strtolower($className);
        
        // Class-specific patterns
        if (str_contains($className, 'model') || str_contains($className, 'entity')) {
            // Database model patterns
            if ($propertyName === 'id') return 'int';
            if (str_ends_with($propertyName, '_id')) return 'int';
            if (str_ends_with($propertyName, '_at')) return 'string'; // Timestamps as strings
            if ($propertyName === 'created_at' || $propertyName === 'updated_at') return 'string';
        }
        
        if (str_contains($className, 'component')) {
            // UI Component patterns
            if ($propertyName === 'attributes' || $propertyName === 'props') return 'array';
            if ($propertyName === 'content' || $propertyName === 'data') return 'string|array';
            if ($propertyName === 'class' || $propertyName === 'classes') return 'string|array';
        }
        
        if (str_contains($className, 'config') || str_contains($className, 'settings')) {
            // Configuration class patterns
            if (str_contains($propertyName, 'mapping') || str_contains($propertyName, 'options')) {
                return 'array';
            }
        }
        
        // Use the same parameter inference logic for properties
        return self::inferParameterType($propertyName, $className, '');
    }
    
    /**
     * Infer return type based on method name and context
     */
    public static function inferReturnType(string $methodName, string $className = ''): string
    {
        $methodName = strtolower($methodName);
        $className = strtolower($className);
        
        // Getter patterns
        if (str_starts_with($methodName, 'get')) {
            $property = substr($methodName, 3);
            return self::inferPropertyType($property, $className);
        }
        
        if (str_starts_with($methodName, 'is') || str_starts_with($methodName, 'has') || str_starts_with($methodName, 'can')) {
            return 'bool';
        }
        
        // Builder/Fluent patterns
        if (str_contains($className, 'builder') || str_contains($className, 'query')) {
            if (str_starts_with($methodName, 'where') || str_starts_with($methodName, 'order') || 
                str_starts_with($methodName, 'group') || str_starts_with($methodName, 'limit')) {
                return 'self'; // Fluent interface
            }
            if ($methodName === 'get' || $methodName === 'all' || str_ends_with($methodName, 'all')) {
                return 'array'; // Collection results
            }
            if ($methodName === 'first' || $methodName === 'find') {
                return 'object|null'; // Single result
            }
            if ($methodName === 'count') {
                return 'int';
            }
        }
        
        // Collection patterns
        if (str_contains($methodName, 'collection') || str_ends_with($methodName, 'list')) {
            return 'array';
        }
        
        // Common method patterns
        $patterns = [
            'count' => 'int', 'size' => 'int', 'length' => 'int',
            'total' => 'int', 'sum' => 'int|float',
            'toarray' => 'array', 'jsonserialize' => 'array',
            'tostring' => 'string', '__tostring' => 'string',
            'render' => 'string', 'build' => 'string',
            'exists' => 'bool', 'empty' => 'bool', 'valid' => 'bool',
        ];
        
        foreach ($patterns as $pattern => $type) {
            if (str_contains($methodName, $pattern)) {
                return $type;
            }
        }
        
        // Component/View patterns
        if (str_contains($className, 'component') || str_contains($className, 'view')) {
            if ($methodName === 'render' || $methodName === 'tohtml') {
                return 'string';
            }
            if (str_starts_with($methodName, 'with') || str_starts_with($methodName, 'set')) {
                return 'self'; // Fluent interface
            }
        }
        
        return 'mixed'; // Fallback when we can't determine
    }
    
    /**
     * Infer type from a default value node
     */
    public static function inferTypeFromValue(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Scalar\String_ => 'string',
            $node instanceof Node\Scalar\LNumber => 'int',
            $node instanceof Node\Scalar\DNumber => 'float',
            $node instanceof Node\Expr\Array_ => self::analyzeArrayValue($node),
            $node instanceof Node\Expr\ConstFetch => self::inferConstType($node),
            $node instanceof Node\Expr\New_ => self::inferNewType($node),
            $node instanceof Node\Expr\ClassConstFetch => 'string', // Most class constants are strings
            default => 'mixed',
        };
    }
    
    /**
     * Analyze array value to infer more specific array type
     */
    private static function analyzeArrayValue(Node\Expr\Array_ $array): string
    {
        if (empty($array->items)) {
            return 'array';
        }
        
        $hasStringKeys = false;
        $hasIntKeys = false;
        $valueTypes = [];
        
        foreach ($array->items as $item) {
            if ($item === null) continue;
            
            // Analyze key type
            if ($item->key !== null) {
                if ($item->key instanceof Node\Scalar\String_) {
                    $hasStringKeys = true;
                } elseif ($item->key instanceof Node\Scalar\LNumber) {
                    $hasIntKeys = true;
                }
            } else {
                $hasIntKeys = true; // No key means numeric index
            }
            
            // Analyze value type
            $valueType = self::inferTypeFromValue($item->value);
            $valueTypes[] = $valueType;
        }
        
        // Determine the most specific array type we can return
        $uniqueValueTypes = array_unique($valueTypes);
        if (count($uniqueValueTypes) === 1 && !str_contains($uniqueValueTypes[0], '|')) {
            $valueType = $uniqueValueTypes[0];
            
            // Return more specific array type if possible
            if (!$hasStringKeys && $hasIntKeys && $valueType !== 'mixed') {
                return 'array'; // Could be array<int, type> but we'll keep simple for PHP declarations
            }
        }
        
        return 'array';
    }
    
    /**
     * Infer type from constant fetch
     */
    private static function inferConstType(Node\Expr\ConstFetch $node): string
    {
        $name = $node->name->toLowerString();
        return match ($name) {
            'true', 'false' => 'bool',
            'null' => 'null',
            default => 'mixed',
        };
    }
    
    /**
     * Infer type from new expression
     */
    private static function inferNewType(Node\Expr\New_ $node): string
    {
        if ($node->class instanceof Node\Name) {
            return $node->class->toString();
        }
        return 'object';
    }
    
    /**
     * Get confidence level for a type inference (0-100)
     */
    public static function getInferenceConfidence(string $name, string $inferredType): int
    {
        $name = strtolower($name);
        
        // High confidence patterns
        $highConfidencePatterns = [
            'id' => 95, 'name' => 85, 'title' => 85, 'email' => 95,
            'count' => 95, 'enabled' => 95, 'active' => 95,
            'content' => 75, 'parameter' => 75, 'data' => 70,
        ];
        
        if (isset($highConfidencePatterns[$name])) {
            return $highConfidencePatterns[$name];
        }
        
        // Medium confidence for partial matches
        if (str_contains($name, 'id') || str_ends_with($name, '_id')) {
            return 85; // Increased confidence for ID patterns
        }
        if (str_contains($name, 'count') || str_contains($name, 'num')) {
            return 80;
        }
        if (str_starts_with($name, 'is_') || str_starts_with($name, 'has_')) {
            return 90;
        }
        if (str_ends_with($name, '_name') || str_ends_with($name, 'name')) {
            return 80;
        }
        
        // Type-based confidence adjustments
        if ($inferredType === 'mixed') {
            return 30;
        }
        if ($inferredType === 'string') {
            return 75; // Increased confidence for string inference
        }
        if ($inferredType === 'int' || $inferredType === 'bool') {
            return 80; // High confidence for primitive types
        }
        if ($inferredType === 'array') {
            return 70;
        }
        
        return 65; // Increased default confidence
    }
}