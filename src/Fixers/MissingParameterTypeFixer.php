<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;
use PHPStanFixer\Cache\FlowCache;

class MissingParameterTypeFixer extends CacheAwareFixer
{
    private SmartTypeAnalyzer $smartAnalyzer;
    private FlowCache $flowCache;

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
        return (bool) preg_match('/Parameter .* has no type specified/', $error->getMessage())
            || (bool) preg_match('/Method .* has parameter \$.+ with no type specified/', $error->getMessage());
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

        // Extract parameter info from error message (two possible formats)
        if (preg_match('/Parameter \\$(\\w+) of method (.*?)::(\\w+)\\(\\) has no type specified/', $error->getMessage(), $m)) {
            [$_, $paramName, $className, $methodName] = $m;
        } elseif (preg_match('/Method (.*?)::(\\w+)\\(\\) has parameter \\$(\\w+) with no type specified/', $error->getMessage(), $m)) {
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
                            
                            // Try smart analysis first – only accept informative types
                            $smartType = $this->smartAnalyzer->getParameterType($this->className, $this->methodName, $this->paramName);
                            if ($smartType && !in_array($smartType, ['mixed', 'null'], true)) {
                                $inferredType = $this->simplifyClassName($smartType);
                            } else {
                                // Fallback to heuristic inference
                                $inferredType = $this->inferParameterType($param, $node);
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
        };

        $this->traverseWithVisitor($stmts, $visitor);
        
        if ($visitor->fix !== null) {
            $pos = $visitor->fix['pos'];
            $text = $visitor->fix['text'];
            $content = substr($content, 0, $pos) . $text . substr($content, $pos);
        }
        
        return $content;
    }
}