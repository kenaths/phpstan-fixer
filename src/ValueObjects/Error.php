<?php

declare(strict_types=1);

namespace PHPStanFixer\ValueObjects;

/**
 * Represents a PHPStan error with enhanced type detection for PHP 8.4
 */
final readonly class Error
{
    private string $type;
    
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
        public ?string $identifier = null,
        public ?int $severity = null
    ) {
        $this->type = $this->detectType($message);
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function getSeverity(): ?int
    {
        return $this->severity;
    }

    private function detectType(string $message): string
    {
        // Enhanced error type detection patterns for PHP 8.4
        $patterns = [
            // Type-related errors
            'missing_return_type' => '/Method .* has no return type specified/',
            'missing_param_type' => '/Parameter .* has no type specified/',
            'missing_property_type' => '/Property .* has no type specified/',
            'incompatible_return_type' => '/Method .* should return .* but returns?/',
            'incompatible_param_type' => '/Parameter .* expects .* given/',
            
            // Variable-related errors
            'undefined_variable' => '/Undefined variable/',
            'unused_variable' => '/Variable .* is never used/',
            'possibly_undefined_variable' => '/Variable .* might not be defined/',
            
            // Comparison and type checking
            'strict_comparison' => '/Strict comparison using === between/',
            'weak_comparison' => '/Comparison using == between/',
            'null_coalescing' => '/isset\(\) construct can be replaced with null coalesce operator/',
            
            // PHPDoc-related
            'invalid_phpdoc' => '/PHPDoc tag @.* has invalid/',
            'missing_phpdoc' => '/Missing PHPDoc/',
            'phpdoc_mismatch' => '/PHPDoc tag .* does not match/',
            
            // PHP 8+ specific
            'readonly_property_write' => '/Cannot assign to readonly property/',
            'enum_case_mismatch' => '/Enum case .* does not exist/',
            'union_type_error' => '/Union type .* is not allowed/',
            'intersection_type_error' => '/Intersection type .* is not allowed/',
            'dnf_type_error' => '/DNF type .* is not allowed/',
            'property_hooks_error' => '/Property hook .* is not compatible/',
            'asymmetric_visibility_error' => '/Asymmetric visibility .* is not allowed/',
            
            // Constructor property promotion
            'constructor_promotion_error' => '/Constructor property promotion .* is not allowed/',
            'promoted_property_type' => '/Promoted property .* must have a type/',
            
            // Named arguments
            'unknown_named_parameter' => '/Unknown named parameter/',
            'duplicate_named_parameter' => '/Duplicate named parameter/',
            
            // Match expression
            'non_exhaustive_match' => '/Match expression does not handle/',
            
            // Nullsafe operator
            'nullsafe_on_non_nullable' => '/Cannot use nullsafe operator on non-nullable/',
            
            // Attributes
            'invalid_attribute' => '/Attribute .* cannot be used/',
            'missing_attribute_argument' => '/Attribute .* is missing required argument/',
            
            // Enums
            'enum_missing_case' => '/Enum .* must implement all cases/',
            'invalid_enum_backing_type' => '/Enum backing type must be/',
            
            // First-class callables
            'invalid_first_class_callable' => '/First-class callable syntax cannot be used/',
            
            // Never type
            'never_type_misuse' => '/Never type can only be used/',
            
            // Static in traits
            'static_in_trait' => '/Cannot use static:: in trait/',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $message)) {
                return $type;
            }
        }

        return 'unknown';
    }

    /**
     * Check if this error is related to PHP 8+ features
     */
    public function isModernPHPError(): bool
    {
        $modernTypes = [
            'readonly_property_write',
            'enum_case_mismatch',
            'union_type_error',
            'intersection_type_error',
            'dnf_type_error',
            'property_hooks_error',
            'asymmetric_visibility_error',
            'constructor_promotion_error',
            'promoted_property_type',
            'unknown_named_parameter',
            'duplicate_named_parameter',
            'non_exhaustive_match',
            'nullsafe_on_non_nullable',
            'invalid_attribute',
            'missing_attribute_argument',
            'enum_missing_case',
            'invalid_enum_backing_type',
            'invalid_first_class_callable',
            'never_type_misuse',
            'static_in_trait'
        ];

        return in_array($this->type, $modernTypes, true);
    }
}