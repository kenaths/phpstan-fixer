<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\ArrayTypeAnalyzer;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;
use PHPStanFixer\Cache\FlowCache;

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
            // Get project root from current file path
            $projectRoot = $this->currentFile ? dirname($this->currentFile) : getcwd();
            while ($projectRoot !== '/' && !file_exists($projectRoot . '/composer.json')) {
                $projectRoot = dirname($projectRoot);
            }
            
            // Fallback to current working directory if no composer.json found
            if ($projectRoot === '/' || !is_dir($projectRoot)) {
                $projectRoot = getcwd();
            }
            
            $this->flowCache = new FlowCache($projectRoot);
            $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache, $this->flowCache);
        }
        if ($this->currentFile) {
            $this->smartAnalyzer->setCurrentFile($this->currentFile);
        }

        // Run smart analysis first
        $this->smartAnalyzer->analyze($stmts);
        
        // Save flow data after analysis
        if ($this->flowCache) {
            $this->flowCache->save();
        }

        // Extract property info from error message
        preg_match('/Property (.*?)::\$(\w+) has no type specified/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        $visitor = new class($propertyName, $error->getLine(), $this->arrayAnalyzer, $this->smartAnalyzer, $className) extends NodeVisitorAbstract {
            private string $propertyName;
            private int $targetLine;
            private ArrayTypeAnalyzer $arrayAnalyzer;
            private SmartTypeAnalyzer $smartAnalyzer;
            private string $className;

            public ?array $fix = null;

            public function __construct(string $propertyName, int $targetLine, ArrayTypeAnalyzer $arrayAnalyzer, SmartTypeAnalyzer $smartAnalyzer, string $className)
            {
                $this->propertyName = $propertyName;
                $this->targetLine = $targetLine;
                $this->arrayAnalyzer = $arrayAnalyzer;
                $this->smartAnalyzer = $smartAnalyzer;
                $this->className = $className;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\Property
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === $this->propertyName
                            && $node->type === null) {
                            
                            // Try smart analysis first
                            $smartType = $this->smartAnalyzer->getPropertyType($this->className, $this->propertyName);
                            if ($smartType) {
                                $inferredType = $this->simplifyClassName($smartType);
                            } else {
                                // Fallback to default inference
                                $inferredType = $this->inferPropertyType($prop, $node);
                            }
                            
                            $insertionPos = $prop->getAttribute('startFilePos');
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
}