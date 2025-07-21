<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

/**
 * Handles generic type detection and resolution for common patterns
 * like Collection<T>, QueryBuilder<Model>, etc.
 */
class GenericTypeHandler
{
    /**
     * Known generic type patterns and their common usages
     */
    private const GENERIC_PATTERNS = [
        // Laravel Collections
        'Illuminate\\Support\\Collection' => [
            'defaultValueType' => 'mixed',
            'defaultKeyType' => 'int|string',
            'commonPatterns' => [
                'items' => 'object',
                'models' => 'object',
                'users' => 'User',
                'posts' => 'Post',
                'data' => 'array',
            ]
        ],
        
        // Laravel Query Builder
        'Illuminate\\Database\\Eloquent\\Builder' => [
            'modelBased' => true,
            'commonMethods' => [
                'where' => 'self',
                'get' => 'Illuminate\\Support\\Collection',
                'first' => 'object|null',
                'find' => 'object|null',
            ]
        ],
        
        // Doctrine Collections
        'Doctrine\\Common\\Collections\\Collection' => [
            'defaultValueType' => 'object',
            'defaultKeyType' => 'int|string',
        ],
        
        // Laravel Pagination
        'Illuminate\\Pagination\\LengthAwarePaginator' => [
            'defaultValueType' => 'mixed',
        ],
        
        // Laravel HTTP Collections
        'Illuminate\\Http\\Resources\\Json\\ResourceCollection' => [
            'defaultValueType' => 'object',
        ],
    ];
    
    /**
     * Detect if a type is a generic type and infer its parameters
     */
    public static function analyzeGenericType(string $typeName, string $context = ''): array
    {
        $typeName = ltrim($typeName, '\\');
        
        // Check if already has generic parameters
        if (str_contains($typeName, '<')) {
            return self::parseExistingGeneric($typeName);
        }
        
        // Check against known patterns
        foreach (self::GENERIC_PATTERNS as $pattern => $config) {
            if ($typeName === $pattern || str_ends_with($typeName, '\\' . basename($pattern))) {
                return self::inferGenericParameters($typeName, $config, $context);
            }
        }
        
        // Check for Collection-like class names
        if (str_contains(strtolower($typeName), 'collection')) {
            return [
                'baseType' => $typeName,
                'isGeneric' => true,
                'valueType' => self::inferValueTypeFromContext($context),
                'keyType' => 'int|string',
                'phpDocType' => $typeName . '<' . self::inferValueTypeFromContext($context) . '>',
                'declarationType' => $typeName, // PHP doesn't support generic syntax in declarations
            ];
        }
        
        return [
            'baseType' => $typeName,
            'isGeneric' => false,
            'phpDocType' => $typeName,
            'declarationType' => $typeName,
        ];
    }
    
    /**
     * Parse existing generic type notation
     */
    private static function parseExistingGeneric(string $typeName): array
    {
        if (preg_match('/^([^<]+)<([^>]+)>$/', $typeName, $matches)) {
            $baseType = $matches[1];
            $parameters = $matches[2];
            
            // Split parameters by comma, handling nested generics
            $params = self::splitGenericParameters($parameters);
            
            return [
                'baseType' => $baseType,
                'isGeneric' => true,
                'parameters' => $params,
                'valueType' => $params[0] ?? 'mixed',
                'keyType' => $params[1] ?? 'int|string',
                'phpDocType' => $typeName,
                'declarationType' => $baseType,
            ];
        }
        
        return [
            'baseType' => $typeName,
            'isGeneric' => false,
            'phpDocType' => $typeName,
            'declarationType' => $typeName,
        ];
    }
    
    /**
     * Infer generic parameters based on context
     */
    private static function inferGenericParameters(string $typeName, array $config, string $context): array
    {
        $valueType = 'mixed';
        $keyType = 'int|string';
        
        // Model-based inference (for Query Builders)
        if (isset($config['modelBased']) && $config['modelBased']) {
            $valueType = self::inferModelFromContext($context);
        }
        
        // Pattern-based inference
        if (isset($config['commonPatterns'])) {
            $contextLower = strtolower($context);
            foreach ($config['commonPatterns'] as $pattern => $type) {
                if (str_contains($contextLower, $pattern)) {
                    $valueType = $type;
                    break;
                }
            }
        }
        
        // Use defaults if nothing specific found
        if ($valueType === 'mixed' && isset($config['defaultValueType'])) {
            $valueType = $config['defaultValueType'];
        }
        if (isset($config['defaultKeyType'])) {
            $keyType = $config['defaultKeyType'];
        }
        
        return [
            'baseType' => $typeName,
            'isGeneric' => true,
            'valueType' => $valueType,
            'keyType' => $keyType,
            'phpDocType' => $typeName . '<' . $valueType . '>',
            'declarationType' => $typeName,
        ];
    }
    
    /**
     * Infer value type from context clues
     */
    private static function inferValueTypeFromContext(string $context): string
    {
        $context = strtolower($context);
        
        // Common entity patterns
        $entityPatterns = [
            'user' => 'User',
            'post' => 'Post', 
            'article' => 'Article',
            'product' => 'Product',
            'order' => 'Order',
            'comment' => 'Comment',
            'category' => 'Category',
            'tag' => 'Tag',
            'item' => 'object',
            'model' => 'object',
            'data' => 'array',
        ];
        
        foreach ($entityPatterns as $pattern => $type) {
            if (str_contains($context, $pattern)) {
                return $type;
            }
        }
        
        return 'mixed';
    }
    
    /**
     * Infer model type from context (for Query Builders)
     */
    private static function inferModelFromContext(string $context): string
    {
        $context = strtolower($context);
        
        // Look for model class patterns
        if (preg_match('/(\w+)(?:query|builder|repository)/i', $context, $matches)) {
            return ucfirst($matches[1]);
        }
        
        // Look for method names that suggest a model
        $modelPatterns = [
            'users' => 'User',
            'posts' => 'Post',
            'articles' => 'Article',
            'products' => 'Product',
            'orders' => 'Order',
        ];
        
        foreach ($modelPatterns as $pattern => $model) {
            if (str_contains($context, $pattern)) {
                return $model;
            }
        }
        
        return 'object';
    }
    
    /**
     * Split generic parameters handling nested generics
     */
    private static function splitGenericParameters(string $parameters): array
    {
        $params = [];
        $current = '';
        $depth = 0;
        $length = strlen($parameters);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $parameters[$i];
            
            if ($char === '<') {
                $depth++;
                $current .= $char;
            } elseif ($char === '>') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $params[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        if ($current !== '') {
            $params[] = trim($current);
        }
        
        return $params;
    }
    
    /**
     * Get suggested PHPDoc annotation for a generic type
     */
    public static function getPhpDocAnnotation(string $typeName, string $context = '', string $annotationType = 'var'): ?string
    {
        $analysis = self::analyzeGenericType($typeName, $context);
        
        if (!$analysis['isGeneric']) {
            return null;
        }
        
        return "@{$annotationType} {$analysis['phpDocType']}";
    }
    
    /**
     * Get the declaration type (what goes in the actual PHP type declaration)
     */
    public static function getDeclarationType(string $typeName, string $context = ''): string
    {
        $analysis = self::analyzeGenericType($typeName, $context);
        return $analysis['declarationType'];
    }
    
    /**
     * Check if a type should have generic parameters added
     */
    public static function shouldAddGenericAnnotation(string $typeName): bool
    {
        $typeName = ltrim($typeName, '\\');
        
        // Already has generic parameters
        if (str_contains($typeName, '<')) {
            return false;
        }
        
        // Check if it's a known generic type
        foreach (array_keys(self::GENERIC_PATTERNS) as $pattern) {
            if ($typeName === $pattern || str_ends_with($typeName, '\\' . basename($pattern))) {
                return true;
            }
        }
        
        // Check for Collection-like names
        return str_contains(strtolower($typeName), 'collection');
    }
    
    /**
     * Get common Laravel collection methods and their return types
     */
    public static function getCollectionMethodReturnTypes(): array
    {
        return [
            'map' => 'Illuminate\\Support\\Collection',
            'filter' => 'Illuminate\\Support\\Collection', 
            'where' => 'Illuminate\\Support\\Collection',
            'pluck' => 'Illuminate\\Support\\Collection',
            'sortBy' => 'Illuminate\\Support\\Collection',
            'groupBy' => 'Illuminate\\Support\\Collection',
            'chunk' => 'Illuminate\\Support\\Collection',
            'slice' => 'Illuminate\\Support\\Collection',
            'take' => 'Illuminate\\Support\\Collection',
            'skip' => 'Illuminate\\Support\\Collection',
            'first' => 'mixed',
            'last' => 'mixed',
            'get' => 'mixed',
            'count' => 'int',
            'isEmpty' => 'bool',
            'isNotEmpty' => 'bool',
            'contains' => 'bool',
            'toArray' => 'array',
            'toJson' => 'string',
            'jsonSerialize' => 'array',
        ];
    }
}