<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\ArrayTypeAnalyzer;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;

class MissingPropertyTypeFixer extends CacheAwareFixer
{
    private ArrayTypeAnalyzer $arrayAnalyzer;
    private SmartTypeAnalyzer $smartAnalyzer;

    public function __construct()
    {
        parent::__construct();
        $this->arrayAnalyzer = new ArrayTypeAnalyzer();
        $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache);
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
            $this->smartAnalyzer = new SmartTypeAnalyzer($this->typeCache);
        }
        if ($this->currentFile) {
            $this->smartAnalyzer->setCurrentFile($this->currentFile);
        }

        // Run smart analysis first
        $this->smartAnalyzer->analyze($stmts);

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
                                $inferredType = $smartType;
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