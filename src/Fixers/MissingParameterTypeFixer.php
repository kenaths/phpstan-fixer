<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class MissingParameterTypeFixer extends AbstractFixer
{
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

        // Extract parameter info from error message (two possible formats)
        if (preg_match('/Parameter \\$(\\w+) of method (.*?)::(\\w+)\\(\\) has no type specified/', $error->getMessage(), $m)) {
            [$_, $paramName, $className, $methodName] = $m;
        } elseif (preg_match('/Method (.*?)::(\\w+)\\(\\) has parameter \\$(\\w+) with no type specified/', $error->getMessage(), $m)) {
            [$_, $className, $methodName, $paramName] = $m;
        } else {
            return $content; // pattern not matched
        }

        $visitor = new class($methodName, $paramName, $error->getLine()) extends NodeVisitorAbstract {
            private string $methodName;
            private string $paramName;
            private int $targetLine;

            public ?array $fix = null;

            public function __construct(string $methodName, string $paramName, int $targetLine)
            {
                $this->methodName = $methodName;
                $this->paramName = $paramName;
                $this->targetLine = $targetLine;
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
                            
                            // Try to infer type from usage or default value
                            $inferredType = $this->inferParameterType($param, $node);
                            $insertionPos = $param->var->getAttribute('startFilePos');
                            $this->fix = ['pos' => $insertionPos, 'text' => $inferredType . ' '];
                        }
                    }
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