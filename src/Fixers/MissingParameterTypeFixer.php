<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;
use PHPStanFixer\Cache\FlowCache;
use PHPStanFixer\Utilities\ProjectRootDetector;
use PHPStanFixer\Utilities\TypeInferrer;

class MissingParameterTypeFixer extends CacheAwareFixer
{
    private array $properties = [];
    private SmartTypeAnalyzer $smartAnalyzer;
    private FlowCache $flowCache;
    
    // Pre-compiled regex patterns for performance
    private const PATTERN_CAN_FIX_1 = '/Parameter .* has no type specified/';
    private const PATTERN_CAN_FIX_2 = '/Method .* has parameter \$.+ with no type specified/';
    private const PATTERN_EXTRACT_1 = '/Parameter \$(\w+) of method (.*?)::(\w+)\(\) has no type specified/';
    private const PATTERN_EXTRACT_2 = '/Method (.*?)::(\w+)\(\) has parameter \$(\w+) with no type specified/';

    public function __construct()
    {
        parent::__construct();
        // We'll initialize these in the fix method when we have the project root
        $this->flowCache = new FlowCache(getcwd()); // temporary, will be updated
        $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache, $this->flowCache);
    }

    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['missing_param_type'];
    }

    public function canFix(Error $error): bool
    {
        $message = $error->getMessage();
        return (bool) preg_match(self::PATTERN_CAN_FIX_1, $message)
            || (bool) preg_match(self::PATTERN_CAN_FIX_2, $message);
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Update smart analyzer with current cache and file
        if ($this->typeCache) {
            $projectRoot = ProjectRootDetector::detectFromFilePath($this->currentFile);
            $this->flowCache = new FlowCache($projectRoot);
            $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache, $this->flowCache);
        }
        if ($this->currentFile) {
            $this->smartAnalyzer->setCurrentFile($this->currentFile);
        }

        // Collect class properties first
        $this->collectProperties($stmts);
        
        // Run smart analysis first
        $this->smartAnalyzer->analyze($stmts);
        
        // Save flow data after analysis
        $this->flowCache->save();

        // Extract parameter info from error message (two possible formats)
        $message = $error->getMessage();
        if (preg_match(self::PATTERN_EXTRACT_1, $message, $m)) {
            [$_, $paramName, $className, $methodName] = $m;
        } elseif (preg_match(self::PATTERN_EXTRACT_2, $message, $m)) {
            [$_, $className, $methodName, $paramName] = $m;
        } else {
            return $content; // pattern not matched
        }

        $visitor = new class($methodName, $paramName, $error->getLine(), $this->smartAnalyzer, $className) extends NodeVisitorAbstract {
            private string $methodName;
            private string $paramName;
            private int $targetLine;
            private SmartTypeAnalyzer $smartAnalyzer;
            private string $className;

            public ?array $fix = null;

            public function __construct(string $methodName, string $paramName, int $targetLine, SmartTypeAnalyzer $smartAnalyzer, string $className)
            {
                $this->methodName = $methodName;
                $this->paramName = $paramName;
                $this->targetLine = $targetLine;
                $this->smartAnalyzer = $smartAnalyzer;
                $this->className = $className;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\ClassMethod 
                    && $node->name->toString() === $this->methodName
                    && $node->getLine() <= $this->targetLine) {
                    
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable
                            && $param->var->name === $this->paramName
                            && $param->type === null) {
                            
                            // Try smart analysis first – but validate the result
                            $smartType = $this->smartAnalyzer->getParameterType($this->className, $this->methodName, $this->paramName);
                            if ($smartType && $this->isValidSmartTypeLocal($smartType)) {
                                $inferredType = $this->simplifyClassName($smartType);
                            } else {
                                // Fallback to enhanced heuristic inference
                                $inferredType = $this->inferParameterTypeEnhanced($param, $node);
                            }
                            
                            // Check if parameter has null default - if so, make it nullable
                            if ($param->default !== null && $param->default instanceof Node\Expr\ConstFetch 
                                && $param->default->name->toString() === 'null') {
                                // Only make it nullable if it's not already a union type
                                if (!str_contains($inferredType, '|')) {
                                    $inferredType = $inferredType . '|null';
                                }
                            }
                            
                            $insertionPos = $param->var->getAttribute('startFilePos');
                            $this->fix = ['pos' => $insertionPos, 'text' => $inferredType . ' '];
                        }
                    }
                }
                
                return null;
            }

            private function simplifyClassName(string $type): string
            {
                // Handle generic types (e.g., array<string> -> array)
                if (str_contains($type, '<')) {
                    $baseType = substr($type, 0, strpos($type, '<'));
                    // For now, just return the base type. In the future, we could add PHPDoc comments
                    return $this->simplifyNamespaceInType($baseType);
                }
                
                return $this->simplifyNamespaceInType($type);
            }
            
            private function simplifyNamespaceInType(string $type): string
            {
                // If the type contains a namespace, check if it's the same as the current class namespace
                if (str_contains($type, '\\')) {
                    $currentNamespace = $this->getCurrentNamespace();
                    if ($currentNamespace) {
                        $prefix = $currentNamespace . '\\';
                        if (str_starts_with($type, $prefix)) {
                            // Remove the namespace prefix if it's the same as current namespace
                            return substr($type, strlen($prefix));
                        }
                    }
                }
                return $type;
            }

            private function getCurrentNamespace(): ?string
            {
                // Extract namespace from the current class name
                if (str_contains($this->className, '\\')) {
                    return substr($this->className, 0, strrpos($this->className, '\\'));
                }
                return null;
            }

            private function inferParameterType(Node\Param $param, Node\Stmt\ClassMethod $method): string
            {
                // Check default value
                if ($param->default !== null) {
                    if ($param->default instanceof Node\Scalar\String_) {
                        return 'string';
                    }
                    if ($param->default instanceof Node\Scalar\LNumber) {
                        return 'int';
                    }
                    if ($param->default instanceof Node\Scalar\DNumber) {
                        return 'float';
                    }
                    if ($param->default instanceof Node\Expr\Array_) {
                        return 'array';
                    }
                    if ($param->default instanceof Node\Expr\ConstFetch) {
                        $name = $param->default->name->toLowerString();
                        if ($name === 'true' || $name === 'false') {
                            return 'bool';
                        }
                        if ($name === 'null') {
                            // When default is null, try to infer from parameter name
                            $paramName = strtolower($this->paramName);
                            if (str_contains($paramName, 'name') || str_contains($paramName, 'title') || str_contains($paramName, 'content')) {
                                return 'string|null';
                            }
                            if (str_contains($paramName, 'id') || str_contains($paramName, 'count') || str_contains($paramName, 'number')) {
                                return 'int|null';
                            }
                            if (str_contains($paramName, 'array') || str_contains($paramName, 'list') || str_contains($paramName, 'options')) {
                                return 'array|null';
                            }
                            return 'mixed';
                        }
                    }
                }

                // Try to infer from parameter name
                $paramName = strtolower($this->paramName);
                if (str_contains($paramName, 'name') || str_contains($paramName, 'title') || str_contains($paramName, 'content')) {
                    return 'string';
                }
                if (str_contains($paramName, 'id') || str_contains($paramName, 'count') || str_contains($paramName, 'number')) {
                    return 'int';
                }
                if (str_contains($paramName, 'array') || str_contains($paramName, 'list') || str_contains($paramName, 'options')) {
                    return 'array';
                }
                if (str_contains($paramName, 'enabled') || str_contains($paramName, 'disabled') || str_contains($paramName, 'is_') || str_contains($paramName, 'has_')) {
                    return 'bool';
                }
                return 'mixed';
            }
            
            private function inferParameterTypeEnhanced(Node\Param $param, Node\Stmt\ClassMethod $method): string
            {
                // Use the enhanced TypeInferrer for better type detection
                $inferredType = TypeInferrer::inferParameterType($this->paramName, $this->className, $this->methodName);
                
                // Validate the confidence level and fall back to old method if confidence is low
                $confidence = TypeInferrer::getInferenceConfidence($this->paramName, $inferredType);
                
                if ($confidence < 60) {
                    // If confidence is low, try the old inference method as backup
                    $oldType = $this->inferParameterType($param, $method);
                    
                    // If old method gives a more specific type than 'mixed', prefer it
                    if ($oldType !== 'mixed' && $inferredType === 'mixed') {
                        return $oldType;
                    }
                    
                    // If both methods agree or new method is more specific, use new method
                    if ($inferredType !== 'mixed') {
                        return $inferredType;
                    }
                    
                    return $oldType;
                }
                
                return $inferredType;
            }
            
            /**
             * Validate if a smart type is safe to use (simplified version for visitor)
             */
            private function isValidSmartTypeLocal(string $smartType): bool
            {
                // Don't accept purely uninformative types
                if (in_array($smartType, ['mixed', 'null', ''], true)) {
                    return false;
                }
                
                // Don't accept complex union types with questionable combinations
                if (str_contains($smartType, '|')) {
                    $unionParts = explode('|', $smartType);
                    $unionParts = array_map('trim', $unionParts);
                    
                    // Reject unions that are too complex or contain contradictory types
                    if (count($unionParts) > 3) {
                        return false;
                    }
                    
                    // Check for conflicting basic types
                    $hasString = in_array('string', $unionParts);
                    $hasArray = in_array('array', $unionParts);
                    
                    // If we have string + array, this is likely wrong (could be used in foreach)
                    if ($hasString && $hasArray) {
                        return false;
                    }
                }
                
                return true;
            }
        };

        $this->traverseWithVisitor($stmts, $visitor);
        
        if ($visitor->fix !== null) {
            $pos = $visitor->fix['pos'];
            $text = $visitor->fix['text'];
            $content = substr($content, 0, $pos) . $text . substr($content, $pos);
        }
        
        return $content;
    }
    
    /**
     * Collect class properties to help with parameter type inference
     */
    private function collectProperties(array $stmts): void
    {
        $this->properties = [];
        $propertiesArray = &$this->properties;
        
        $visitor = new class($propertiesArray) extends NodeVisitorAbstract {
            private array $properties;
            
            public function __construct(array &$properties)
            {
                $this->properties = &$properties;
            }
            
            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $propertyName = $prop->name->toString();
                        $propertyType = null;
                        
                        // Get property type
                        if ($node->type !== null) {
                            $propertyType = $this->getTypeFromNode($node->type);
                        }
                        
                        $this->properties[$propertyName] = $propertyType;
                    }
                }
                
                return null;
            }
            
            private function getTypeFromNode(?Node $type): ?string
            {
                if ($type === null) {
                    return null;
                }
                
                if ($type instanceof Node\Name) {
                    return $type->toString();
                }
                
                if ($type instanceof Node\Identifier) {
                    return $type->toString();
                }
                
                if ($type instanceof Node\NullableType) {
                    $innerType = $this->getTypeFromNode($type->type);
                    return $innerType ? $innerType . '|null' : null;
                }
                
                if ($type instanceof Node\UnionType) {
                    $types = [];
                    foreach ($type->types as $unionType) {
                        $innerType = $this->getTypeFromNode($unionType);
                        if ($innerType) {
                            $types[] = $innerType;
                        }
                    }
                    return implode('|', $types);
                }
                
                return null;
            }
        };
        
        $this->traverseWithVisitor($stmts, $visitor);
    }
    
    /**
     * Validate if a smart type is safe to use
     */
    private function isValidSmartType(string $smartType): bool
    {
        // Don't accept purely uninformative types
        if (in_array($smartType, ['mixed', 'null', ''], true)) {
            return false;
        }
        
        // Don't accept complex union types with questionable combinations
        if (str_contains($smartType, '|')) {
            $unionParts = explode('|', $smartType);
            $unionParts = array_map('trim', $unionParts);
            
            // Reject unions that are too complex or contain contradictory types
            if (count($unionParts) > 3) {
                return false;
            }
            
            // Check for conflicting basic types
            $hasString = in_array('string', $unionParts);
            $hasArray = in_array('array', $unionParts);
            $hasInt = in_array('int', $unionParts);
            $hasBool = in_array('bool', $unionParts);
            
            // If we have string + array, this is likely wrong (could be used in foreach)
            if ($hasString && $hasArray) {
                return false;
            }
            
            // If we have too many conflicting basic types, be conservative
            $basicTypeCount = (int)$hasString + (int)$hasArray + (int)$hasInt + (int)$hasBool;
            if ($basicTypeCount > 2) {
                return false;
            }
        }
        
        // Don't accept types that look like class names but may not exist
        if (!$this->isBuiltinType($smartType) && !$this->isLikelyValidClassName($smartType)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if a type is a built-in PHP type
     */
    private function isBuiltinType(string $type): bool
    {
        $builtinTypes = [
            'string', 'int', 'float', 'bool', 'array', 'object', 'callable',
            'resource', 'null', 'void', 'mixed', 'never', 'true', 'false'
        ];
        
        // Handle union types
        if (str_contains($type, '|')) {
            $parts = explode('|', $type);
            foreach ($parts as $part) {
                $part = trim($part);
                if (!in_array($part, $builtinTypes) && !str_starts_with($part, '\\')) {
                    return false;
                }
            }
            return true;
        }
        
        return in_array($type, $builtinTypes);
    }
    
    /**
     * Basic validation that a class name looks reasonable
     */
    private function isLikelyValidClassName(string $className): bool
    {
        // Remove leading backslash
        $className = ltrim($className, '\\');
        
        // Should contain at least one namespace separator for non-global classes
        if (!str_contains($className, '\\') && !ctype_upper($className[0])) {
            return false;
        }
        
        // Basic pattern validation
        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $className) === 1;
    }
}