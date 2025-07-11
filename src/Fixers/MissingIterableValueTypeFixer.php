<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes missing iterable value type errors by adding proper PHPDoc annotations
 */
class MissingIterableValueTypeFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['missingType.iterableValue'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match(
            '/(?:Property|Method|Function|Parameter).*has no value type specified in iterable type array/',
            $error->getMessage()
        );
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract information from error message
        preg_match('/(?:Property|Method) ([^:]+)::\$?(\w+)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $memberName = $matches[2] ?? '';

        // Also check for parameter errors
        if (strpos($error->getMessage(), 'parameter') !== false) {
            preg_match('/parameter \$(\w+)/', $error->getMessage(), $paramMatches);
            if (isset($paramMatches[1])) {
                $memberName = $paramMatches[1];
            }
        }

        $visitor = new class($memberName, $error->getLine()) extends NodeVisitorAbstract {
            private string $targetName;
            private int $targetLine;

            public function __construct(string $targetName, int $targetLine)
            {
                $this->targetName = $targetName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node)
            {
                // Handle property declarations
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === $this->targetName 
                            && abs($node->getLine() - $this->targetLine) <= 5) {
                            $this->addIterableTypeDoc($node);
                            return $node;
                        }
                    }
                }

                // Handle method return types
                if ($node instanceof Node\Stmt\ClassMethod 
                    && $node->name->toString() === $this->targetName
                    && abs($node->getLine() - $this->targetLine) < 10) {
                    $this->addIterableReturnTypeDoc($node);
                    return $node;
                }

                // Handle function parameters
                if ($node instanceof Node\Stmt\Function_ 
                    && $node->name->toString() === $this->targetName) {
                    $this->addIterableParamTypeDoc($node);
                    return $node;
                }

                // Handle method parameters
                if ($node instanceof Node\Stmt\ClassMethod) {
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable 
                            && $param->var->name === $this->targetName) {
                            $this->addIterableParamDocToMethod($node, $this->targetName);
                            return $node;
                        }
                    }
                }

                return null;
            }

            private function addIterableTypeDoc(Node\Stmt\Property $node): void
            {
                $existingDoc = $node->getDocComment();
                $docText = $existingDoc ? $existingDoc->getText() : '';

                // Check if it already has a @var tag
                if (strpos($docText, '@var') !== false) {
                    // Update existing @var tag
                    $docText = preg_replace(
                        '/@var\s+array\b/',
                        '@var array<mixed>',
                        $docText
                    );
                } else {
                    // Add new @var tag
                    if (empty($docText) || $docText === '/** */') {
                        $docText = "/**\n     * @var array<mixed>\n     */";
                    } else {
                        $docText = str_replace('*/', "* @var array<mixed>\n */", $docText);
                    }
                }

                $node->setDocComment(new Doc($docText));
            }

            private function addIterableReturnTypeDoc(Node\Stmt\ClassMethod $node): void
            {
                $existingDoc = $node->getDocComment();
                $docText = $existingDoc ? $existingDoc->getText() : '';

                // Check if it already has a @return tag
                if (strpos($docText, '@return') !== false) {
                    // Update existing @return tag
                    $docText = preg_replace(
                        '/@return\s+array\b/',
                        '@return array<mixed>',
                        $docText
                    );
                } else {
                    // Add new @return tag
                    if (empty($docText)) {
                        $docText = "/**\n * @return array<mixed>\n */";
                    } else {
                        $docText = str_replace('*/', "* @return array<mixed>\n */", $docText);
                    }
                }

                $node->setDocComment(new Doc($docText));
            }

            private function addIterableParamTypeDoc(Node\Stmt\Function_ $node): void
            {
                $this->addParamDocs($node);
            }

            private function addIterableParamDocToMethod(Node\Stmt\ClassMethod $node, string $paramName): void
            {
                $this->addParamDocs($node, $paramName);
            }

            private function addParamDocs(Node $node, ?string $specificParam = null): void
            {
                $existingDoc = $node->getDocComment();
                $docText = $existingDoc ? $existingDoc->getText() : '';

                if (empty($docText)) {
                    $docText = "/**\n";
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable) {
                            $paramName = $param->var->name;
                            if ($specificParam === null || $paramName === $specificParam) {
                                $type = $this->getParamType($param);
                                if ($type === 'array') {
                                    $type = 'array<mixed>';
                                }
                                $docText .= " * @param {$type} \${$paramName}\n";
                            }
                        }
                    }
                    $docText .= " */";
                } else {
                    // Update existing param docs
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable) {
                            $paramName = $param->var->name;
                            if ($specificParam === null || $paramName === $specificParam) {
                                $pattern = '/@param\s+array\s+\$' . preg_quote($paramName, '/') . '\b/';
                                if (preg_match($pattern, $docText)) {
                                    $docText = preg_replace(
                                        $pattern,
                                        '@param array<mixed> $' . $paramName,
                                        $docText
                                    );
                                } else if (!preg_match('/@param\s+\S+\s+\$' . preg_quote($paramName, '/') . '\b/', $docText)) {
                                    // Add missing param doc
                                    $docText = str_replace('*/', "* @param array<mixed> \${$paramName}\n */", $docText);
                                }
                            }
                        }
                    }
                }

                $node->setDocComment(new Doc($docText));
            }

            private function getParamType(Node\Param $param): string
            {
                if ($param->type === null) {
                    return 'mixed';
                }

                if ($param->type instanceof Node\Name) {
                    return $param->type->toString();
                }

                if ($param->type instanceof Node\Identifier) {
                    return $param->type->toString();
                }

                return 'mixed';
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}