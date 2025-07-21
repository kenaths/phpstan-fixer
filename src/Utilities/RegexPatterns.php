<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

/**
 * Centralized regex patterns for performance optimization
 * Pre-compiled patterns reduce overhead from repeated regex compilation
 */
class RegexPatterns
{
    // Common error message patterns
    public const UNDEFINED_VARIABLE = '/Undefined variable/';
    public const UNUSED_VARIABLE = '/Variable .* is never used/';
    public const MISSING_RETURN_TYPE = '/Method .* has no return type specified|Method .* should return/';
    public const MISSING_PROPERTY_TYPE = '/Property .* has no type specified/';
    public const STRICT_COMPARISON = '/Strict comparison using === between/';
    public const NULL_COALESCING = '/isset\(\) construct can be replaced with null coalesce operator/';
    public const ENUM_RELATED = '/Enum case|Enum .* must|Enum backing type/';
    public const UNION_TYPE = '/Union type|expects .* given|should return .* but returns/';
    public const READONLY_PROPERTY = '/readonly property|Property .* has no type specified/';
    public const DOCBLOCK_TAG = '/PHPDoc tag @/';
    public const ASYMMETRIC_VISIBILITY = '/Asymmetric visibility/';
    public const CONSTRUCTOR_PROMOTION = '/Constructor property promotion|Promoted property/';
    
    // Common extraction patterns
    public const EXTRACT_VARIABLE_NAME = '/Undefined variable: \$(\w+)/';
    public const EXTRACT_UNUSED_VARIABLE = '/Variable \$(\w+) is never used/';
    public const EXTRACT_METHOD_INFO = '/Method (.*?)::(\w+)\(\)/';
    public const EXTRACT_PROPERTY_INFO = '/Property (.*?)::\$(\w+)/';
    public const EXTRACT_CLASS_PROPERTY = '/(?:Property |error for )(.*?)::\$(\w+)/';
    
    // Type-related patterns
    public const EXTRACT_RETURN_TYPE = '/@return\s+([^\s]+)/';
    public const EXTRACT_PARAM_TYPE = '/@param\s+\$(\w+)\s+(\w+)/';
    public const CHECK_RETURN_TAG = '/@return\s*$/';
    public const CHECK_PARAM_TAG = '/@param\s+\S+\s+\$%s\b/';
    
    // Performance optimized method for simple string checks
    public static function containsAny(string $text, array $substrings): bool
    {
        foreach ($substrings as $substring) {
            if (str_contains($text, $substring)) {
                return true;
            }
        }
        return false;
    }
    
    // Performance optimized method for prefix checks
    public static function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }
        return false;
    }
    
    // Common boolean method prefixes that don't reveal type information
    public const BOOLEAN_METHOD_PREFIXES = ['is', 'has', 'can', 'should', 'will', 'does'];
    
    // Quick check for boolean methods without regex
    public static function isBooleanMethod(string $methodName): bool
    {
        return self::startsWithAny($methodName, self::BOOLEAN_METHOD_PREFIXES);
    }
    
    // Common type keywords for quick checks
    public const TYPE_KEYWORDS = [
        'string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'void', 'null',
        'callable', 'iterable', 'resource', 'true', 'false', 'self', 'parent', 'static'
    ];
    
    // Quick check if a string is a basic type
    public static function isBasicType(string $type): bool
    {
        return in_array(strtolower($type), self::TYPE_KEYWORDS, true);
    }
    
    // Optimized class name extraction without regex for simple cases
    public static function extractShortClassName(string $fullClassName): string
    {
        $lastBackslash = strrpos($fullClassName, '\\');
        return $lastBackslash !== false ? substr($fullClassName, $lastBackslash + 1) : $fullClassName;
    }
    
    // Optimized namespace extraction
    public static function extractNamespace(string $fullClassName): string
    {
        $lastBackslash = strrpos($fullClassName, '\\');
        return $lastBackslash !== false ? substr($fullClassName, 0, $lastBackslash) : '';
    }
    
    // Pre-compiled complex patterns for specific use cases
    public const COMPLEX_PATTERNS = [
        'phpDoc_param_with_type' => '/@param\s+([^\s]+)\s+\$(\w+)/',
        'phpDoc_return_with_type' => '/@return\s+([^\s]+)/',
        'method_signature' => '/(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(/i',
        'property_declaration' => '/(?:public|protected|private)\s+(?:readonly\s+)?(?:\??\w+(?:\|[\w\\\\]+)*\s+)?\$(\w+)/i',
        'class_declaration' => '/class\s+(\w+)(?:\s+extends\s+[\w\\\\]+)?(?:\s+implements\s+[\w\\\\,\s]+)?/i',
    ];
    
    // Get a pre-compiled pattern
    public static function getPattern(string $key): ?string
    {
        return self::COMPLEX_PATTERNS[$key] ?? null;
    }
}