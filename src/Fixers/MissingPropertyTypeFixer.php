<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\ArrayTypeAnalyzer;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;
use PHPStanFixer\Cache\FlowCache;
use PHPStanFixer\Utilities\ProjectRootDetector;
use PHPStanFixer\Utilities\IndentationHelper;
use PHPStanFixer\Utilities\TypeInferrer;

class MissingPropertyTypeFixer extends CacheAwareFixer
{
    private ArrayTypeAnalyzer $arrayAnalyzer;
    private SmartTypeAnalyzer $smartAnalyzer;
    private FlowCache $flowCache;

    public function __construct()
    {
        parent::__construct();
        $this->arrayAnalyzer = new ArrayTypeAnalyzer();
        // We'll initialize these in the fix method when we have the project root
        $this->flowCache = new FlowCache(getcwd()); // temporary, will be updated
        $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache, $this->flowCache);
    }
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['missing_property_type'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Property .* has no type specified/', $error->getMessage());
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

        // Run smart analysis first
        $this->smartAnalyzer->analyze($stmts);
        
        // Save flow data after analysis
        $this->flowCache->save();

        // Extract property info from error message
        preg_match('/Property (.*?)::\$(\w+) has no type specified/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        $visitor = new class($propertyName, $error->getLine(), $this->arrayAnalyzer, $this->smartAnalyzer, $className, $content) extends NodeVisitorAbstract {
            private string $propertyName;
            private int $targetLine;
            private ArrayTypeAnalyzer $arrayAnalyzer;
            private SmartTypeAnalyzer $smartAnalyzer;
            private string $className;
            private string $originalContent;

            public ?array $fix = null;

            public function __construct(string $propertyName, int $targetLine, ArrayTypeAnalyzer $arrayAnalyzer, SmartTypeAnalyzer $smartAnalyzer, string $className, string $originalContent)
            {
                $this->propertyName = $propertyName;
                $this->targetLine = $targetLine;
                $this->arrayAnalyzer = $arrayAnalyzer;
                $this->smartAnalyzer = $smartAnalyzer;
                $this->className = $className;
                $this->originalContent = $originalContent;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\Property
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === $this->propertyName
                            && $node->type === null) {
                            
                            // Try smart analysis first – but validate the result
                            $smartType = $this->smartAnalyzer->getPropertyType($this->className, $this->propertyName);
                            if ($smartType && $this->isValidSmartTypeLocal($smartType)) {
                                $inferredType = $this->simplifyClassName($smartType);
                                
                                // Additional validation for class types - but only reject clearly invalid types
                                // Don't validate class existence for types that look reasonable
                                if (!$this->isBuiltinType($inferredType) && !$this->smartAnalyzer->validateClassType($inferredType)) {
                                    // Only reject if the type doesn't look like a valid class name at all
                                    if (!$this->isLikelyValidClassNameLocal($inferredType)) {
                                        return null;
                                    }
                                }
                            } else {
                                // Fallback to enhanced type inference
                                $inferredType = $this->inferPropertyTypeEnhanced($prop, $node);
                            }
                            
                            // Check if we need to add a PHPDoc comment for generic types
                            if (str_contains($inferredType, '<')) {
                                // Add PHPDoc comment with proper indentation
                                $docContent = "@var " . $inferredType;
                                IndentationHelper::addDocCommentToNode($node, $docContent, $this->originalContent);
                                
                                // Extract the base type for the property declaration
                                $baseType = substr($inferredType, 0, strpos($inferredType, '<'));
                                $insertionPos = $prop->getAttribute('startFilePos');
                                $this->fix = ['pos' => $insertionPos, 'text' => $baseType . ' '];
                            } else {
                                // Simple type - just add it to the property declaration
                                $insertionPos = $prop->getAttribute('startFilePos');
                                $this->fix = ['pos' => $insertionPos, 'text' => $inferredType . ' '];
                            }
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

            private function inferPropertyType(Node\PropertyItem $prop, Node\Stmt\Property $property): string
            {
                if ($prop->default !== null) {
                    if ($prop->default instanceof Node\Scalar\String_) {
                        return 'string';
                    }
                    if ($prop->default instanceof Node\Scalar\LNumber) {
                        return 'int';
                    }
                    if ($prop->default instanceof Node\Scalar\DNumber) {
                        return 'float';
                    }
                    if ($prop->default instanceof Node\Expr\Array_) {
                        // Analyze array type
                        $arrayTypes = $this->arrayAnalyzer->analyzeArrayType($property, $this->propertyName);
                        $keyType = $arrayTypes['key'];
                        $valueType = $arrayTypes['value'];
                        
                        // For now, return simple array type - we'd need to enhance PHP-Parser
                        // to support generic array syntax in type declarations
                        return 'array';
                    }
                    if ($prop->default instanceof Node\Expr\ConstFetch) {
                        $name = $prop->default->name->toLowerString();
                        if ($name === 'true' || $name === 'false') {
                            return 'bool';
                        }
                        if ($name === 'null') {
                            // When default is null, try to infer from property name or use string|null
                            $propName = strtolower($this->propertyName);
                            if (str_contains($propName, 'name') || str_contains($propName, 'title') || str_contains($propName, 'content')) {
                                return 'string|null';
                            }
                            if (str_contains($propName, 'id') || str_contains($propName, 'count') || str_contains($propName, 'number')) {
                                return 'int|null';
                            }
                            if (str_contains($propName, 'array') || str_contains($propName, 'list') || str_contains($propName, 'options')) {
                                return 'array|null';
                            }
                            return 'mixed';
                        }
                    }
                }

                // When no default value, try to infer from property name
                $propName = strtolower($this->propertyName);
                if (str_contains($propName, 'name') || str_contains($propName, 'title') || str_contains($propName, 'content')) {
                    return 'string|null';
                }
                if (str_contains($propName, 'id') || str_contains($propName, 'count') || str_contains($propName, 'number')) {
                    return 'int|null';
                }
                if (str_contains($propName, 'array') || str_contains($propName, 'list') || str_contains($propName, 'options')) {
                    return 'array';
                }
                if (str_contains($propName, 'enabled') || str_contains($propName, 'disabled') || str_contains($propName, 'is_') || str_contains($propName, 'has_')) {
                    return 'bool';
                }
                return 'mixed';
            }
            
            private function isBuiltinType(string $type): bool
            {
                $builtinTypes = [
                    'array', 'string', 'int', 'float', 'bool', 'object', 'resource', 'null',
                    'mixed', 'void', 'never', 'true', 'false', 'callable', 'iterable',
                    'self', 'parent', 'static'
                ];
                
                return in_array(strtolower($type), $builtinTypes, true);
            }
            
            private function inferPropertyTypeEnhanced(Node\PropertyItem $prop, Node\Stmt\Property $property): string
            {
                // Use the enhanced TypeInferrer for better type detection
                $inferredType = TypeInferrer::inferPropertyType($this->propertyName, $this->className, $prop->default);
                
                // Validate the confidence level and fall back to old method if confidence is low
                $confidence = TypeInferrer::getInferenceConfidence($this->propertyName, $inferredType);
                
                if ($confidence < 60) {
                    // If confidence is low, try the old inference method as backup
                    $oldType = $this->inferPropertyType($prop, $property);
                    
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
            
            /**
             * Basic validation that a class name looks reasonable (local version for visitor)
             */
            private function isLikelyValidClassNameLocal(string $className): bool
            {
                // Remove leading backslash
                $className = ltrim($className, '\\\\');
                
                // Should contain at least one namespace separator for non-global classes
                if (!str_contains($className, '\\\\') && !ctype_upper($className[0])) {
                    return false;
                }
                
                // Basic pattern validation
                return preg_match('/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff\\\\\\\\]*$/', $className) === 1;
            }
        };

        $this->traverseWithVisitor($stmts, $visitor);
        
        if ($visitor->fix !== null) {
            $pos = $visitor->fix['pos'];
            $text = $visitor->fix['text'];
            $content = substr($content, 0, $pos) . $text . substr($content, $pos);
            
            // Report the discovered type to cache
            $this->reportPropertyType($className, $propertyName, trim($text));
        }
        
        // Save all discovered types from smart analyzer
        $this->smartAnalyzer->saveDiscoveredTypes();
        
        return $content;
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