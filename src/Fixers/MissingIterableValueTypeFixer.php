<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Analyzers\ArrayTypeAnalyzer;
use PHPStanFixer\Utilities\IndentationHelper;

/**
 * Fixes missing iterable value type errors by adding proper PHPDoc annotations.
 * 
 * This fixer handles:
 * - Property array type annotations (@var array<mixed>)
 * - Method return type annotations (@return array<mixed>)
 * - Method parameter type annotations (@param array<mixed>)
 * - Proper indentation preservation for all PHPDoc comments
 * 
 * @author PHPStan Fixer
 * @version 2.0
 */
class MissingIterableValueTypeFixer extends AbstractFixer
{
    private ArrayTypeAnalyzer $arrayAnalyzer;

    public function __construct()
    {
        parent::__construct();
        $this->arrayAnalyzer = new ArrayTypeAnalyzer();
    }
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
            '/(?:Property|Method|Function|Parameter).*has (?:parameter \$\w+ with )?no value type specified in iterable type array/',
            $error->getMessage()
        );
    }

    public function fix(string $content, Error $error): string
    {
        try {
            // Input validation
            if (empty($content)) {
                throw new \InvalidArgumentException('Content cannot be empty');
            }

            $stmts = $this->parseCode($content);
            if ($stmts === null) {
                throw new \RuntimeException('Failed to parse PHP code');
            }

            // Extract information from error message with enhanced error handling
            if (!preg_match('/(?:Property|Method) ([^:]+)::\$?(\w+)/', $error->getMessage(), $matches)) {
                // Try alternative patterns for edge cases
                if (!preg_match('/(?:Function|Parameter).*?(\w+)/', $error->getMessage(), $matches)) {
                    throw new \RuntimeException('Could not extract class/method information from error message: ' . $error->getMessage());
                }
                $matches = ['', '', $matches[1] ?? '']; // Normalize matches array
            }
        $className = $matches[1] ?? '';
        $memberName = $matches[2] ?? '';

        // Also check for parameter errors
        if (strpos($error->getMessage(), 'parameter') !== false) {
            preg_match('/parameter \$(\w+)/', $error->getMessage(), $paramMatches);
            if (isset($paramMatches[1])) {
                $memberName = $paramMatches[1];
            }
        }
        
        // Handle method parameter errors specifically
        $paramName = null;
        if (strpos($error->getMessage(), 'has parameter') !== false) {
            preg_match('/Method ([^:]+)::(\w+)\(\) has parameter \$(\w+)/', $error->getMessage(), $methodMatches);
            if (isset($methodMatches[1], $methodMatches[2], $methodMatches[3])) {
                $className = $methodMatches[1];
                $memberName = $methodMatches[2]; // method name
                $paramName = $methodMatches[3]; // parameter name
            }
        }

        $visitor = new class($memberName, $error->getLine(), $paramName, $this->arrayAnalyzer, $content) extends NodeVisitorAbstract {
            private string $targetName;
            private int $targetLine;
            private ?string $paramName;
            private ArrayTypeAnalyzer $arrayAnalyzer;
            private string $originalContent;

            public function __construct(string $targetName, int $targetLine, ?string $paramName, ArrayTypeAnalyzer $arrayAnalyzer, string $originalContent)
            {
                $this->targetName = $targetName;
                $this->targetLine = $targetLine;
                $this->paramName = $paramName;
                $this->arrayAnalyzer = $arrayAnalyzer;
                $this->originalContent = $originalContent;
            }

            public array $fixes = [];

            public function enterNode(Node $node): ?Node
            {
                // Handle property declarations
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === $this->targetName 
                            && abs($node->getLine() - $this->targetLine) <= 5) {
                            $this->addIterableTypeDoc($node);
                            return null;
                        }
                    }
                }

                // Handle method parameters first (takes priority over return types)
                if ($node instanceof Node\Stmt\ClassMethod) {
                    // If we have a specific parameter name, use it
                    if ($this->paramName !== null) {
                        if ($node->name->toString() === $this->targetName) {
                            foreach ($node->params as $param) {
                                if ($param->var instanceof Node\Expr\Variable 
                                    && $param->var->name === $this->paramName) {
                                    $this->addIterableParamDocToMethod($node, $this->paramName);
                                    return null;
                                }
                            }
                        }
                    } else {
                        // Fallback to old behavior
                        foreach ($node->params as $param) {
                            if ($param->var instanceof Node\Expr\Variable 
                                && $param->var->name === $this->targetName) {
                                $this->addIterableParamDocToMethod($node, $this->targetName);
                                return null;
                            }
                        }
                    }
                }

                // Handle method return types (only if we don't have a parameter name)
                if ($node instanceof Node\Stmt\ClassMethod 
                    && $this->paramName === null
                    && $node->name->toString() === $this->targetName
                    && abs($node->getLine() - $this->targetLine) < 10) {
                    $this->addIterableReturnTypeDoc($node);
                    return null;
                }

                // Handle function parameters
                if ($node instanceof Node\Stmt\Function_ 
                    && $node->name->toString() === $this->targetName) {
                    $this->addIterableParamTypeDoc($node);
                    return null;
                }

                return null;
            }

            private function addFix(int $start, int $end, string $text): void
            {
                $this->fixes[] = ['start' => $start, 'end' => $end, 'text' => $text];
            }

            private function addIterableTypeDoc(Node\Stmt\Property $node): void
            {
                // Analyze the property to determine array types
                $arrayTypes = $this->arrayAnalyzer->analyzeArrayType($node, $this->targetName);
                $arrayType = $this->formatArrayType($arrayTypes);

                $existingDoc = $node->getDocComment();
                
                if ($existingDoc) {
                    // Update existing PHPDoc comment
                    $docText = $existingDoc->getText();
                    
                    // Check if it already has a @var tag
                    if (strpos($docText, '@var') !== false) {
                        // Update existing @var tag
                        $docText = preg_replace(
                            '/@var\s+array(?!\<)/',
                            '@var ' . $arrayType,
                            $docText
                        );
                    } else {
                        // Add @var tag to existing comment
                        $docText = str_replace('*/', "* @var " . $arrayType . "\n */", $docText);
                    }
                    
                    $start = $existingDoc->getStartFilePos();
                    $end = $existingDoc->getEndFilePos() + 1;
                    $this->addFix($start, $end, $docText);
                } else {
                    // Add new PHPDoc comment with proper indentation
                    $docContent = "@var " . $arrayType;
                    $baseIndentation = IndentationHelper::getNodeIndentation($node, $this->originalContent);
                    $formattedDoc = IndentationHelper::formatDocBlock($docContent, $baseIndentation);
                    
                    // Find the beginning of the line to insert PHPDoc at correct position
                    $lineStart = $this->calculateLineStart($node);
                    
                    $this->addFix($lineStart, $lineStart, $formattedDoc . "\n");
                }
            }

            private function addIterableReturnTypeDoc(Node\Stmt\ClassMethod $node): void
            {
                // Analyze the method to determine array types
                $arrayTypes = $this->analyzeMethodReturnArrayType($node);
                $arrayType = $this->formatArrayType($arrayTypes);

                $existingDoc = $node->getDocComment();
                
                if ($existingDoc) {
                    // Update existing PHPDoc comment
                    $docText = $existingDoc->getText();
                    
                    // Check if it already has a @return tag
                    if (strpos($docText, '@return') !== false) {
                        // Update existing @return tag
                        $docText = preg_replace(
                            '/@return\s+array(?!\<)/',
                            '@return ' . $arrayType,
                            $docText
                        );
                    } else {
                        // Add @return tag to existing comment
                        $docText = str_replace('*/', "* @return " . $arrayType . "\n */", $docText);
                    }
                    
                    $start = $existingDoc->getStartFilePos();
                    $end = $existingDoc->getEndFilePos() + 1;
                    $this->addFix($start, $end, $docText);
                } else {
                    // Add new PHPDoc comment with proper indentation
                    $docContent = "@return " . $arrayType;
                    $baseIndentation = IndentationHelper::getNodeIndentation($node, $this->originalContent);
                    $formattedDoc = IndentationHelper::formatDocBlock($docContent, $baseIndentation);
                    
                    // Find the beginning of the line to insert PHPDoc at correct position
                    $lineStart = $this->calculateLineStart($node);
                    
                    $this->addFix($lineStart, $lineStart, $formattedDoc . "\n");
                }
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
                
                if ($existingDoc) {
                    // Update existing PHPDoc comment
                    $docText = $existingDoc->getText();
                    
                    if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
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
                                        $paramNode = $this->findParamNode($node, $paramName);
                                        if ($paramNode !== null) {
                                            $arrayTypes = $this->arrayAnalyzer->analyzeArrayType($paramNode, $paramName, $node);
                                            $arrayType = $this->formatArrayType($arrayTypes);
                                        } else {
                                            $arrayType = 'array<mixed>';
                                        }
                                        $docText = str_replace('*/', "* @param " . $arrayType . " \${$paramName}\n */", $docText);
                                    }
                                }
                            }
                        }
                    }
                    
                    $start = $existingDoc->getStartFilePos();
                    $end = $existingDoc->getEndFilePos() + 1;
                    $this->addFix($start, $end, $docText);
                } else {
                    // Create new PHPDoc comment with proper indentation
                    $paramDocs = [];
                    
                    if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                        foreach ($node->params as $param) {
                            if ($param->var instanceof Node\Expr\Variable) {
                                $paramName = $param->var->name;
                                if ($specificParam === null || $paramName === $specificParam) {
                                    $type = $this->getParamType($param);
                                    if ($type === 'array') {
                                        $paramNode = $this->findParamNode($node, $paramName);
                                        if ($paramNode !== null) {
                                            $arrayTypes = $this->arrayAnalyzer->analyzeArrayType($paramNode, $paramName, $node);
                                            $type = $this->formatArrayType($arrayTypes);
                                        } else {
                                            $type = 'array<mixed>';
                                        }
                                    }
                                    $paramDocs[] = "@param {$type} \${$paramName}";
                                }
                            }
                        }
                    }
                    
                    if (!empty($paramDocs)) {
                        $docContent = implode("\n", $paramDocs);
                        $baseIndentation = IndentationHelper::getNodeIndentation($node, $this->originalContent);
                        $formattedDoc = IndentationHelper::formatDocBlock($docContent, $baseIndentation);
                        
                        // Find the beginning of the line to insert PHPDoc at correct position
                        $nodePos = $node->getAttribute('startFilePos');
                        $lineStart = strrpos(substr($this->originalContent, 0, $nodePos), "\n");
                        $lineStart = ($lineStart === false) ? 0 : $lineStart + 1;
                        
                        $this->addFix($lineStart, $lineStart, $formattedDoc . "\n");
                    }
                }
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

            /**
             * Format array type based on analysis
             * 
             * @param array{key: string, value: string} $types
             */
            private function formatArrayType(array $types): string
            {
                $keyType = $types['key'];
                $valueType = $types['value'];

                // For numeric arrays (int keys), use simple array<value> notation
                if ($keyType === 'int') {
                    return 'array<' . $valueType . '>';
                }

                // For associative arrays, use array<key, value> notation
                return 'array<' . $keyType . ', ' . $valueType . '>';
            }

            /**
             * Find parameter node by name
             */
            private function findParamNode(Node $methodOrFunction, string $paramName): ?Node\Param
            {
                if (!($methodOrFunction instanceof Node\Stmt\ClassMethod || $methodOrFunction instanceof Node\Stmt\Function_)) {
                    return null;
                }

                foreach ($methodOrFunction->params as $param) {
                    if ($param->var instanceof Node\Expr\Variable && $param->var->name === $paramName) {
                        return $param;
                    }
                }

                return null;
            }

            /**
             * Analyze method return array type
             * 
             * @return array{key: string, value: string}
             */
            private function analyzeMethodReturnArrayType(Node\Stmt\ClassMethod $method): array
            {
                // Find return statements
                $returns = [];
                $this->findReturnStatements($method, $returns);

                $keyTypes = [];
                $valueTypes = [];

                foreach ($returns as $return) {
                    if ($return->expr instanceof Node\Expr\Array_) {
                        $this->analyzeArrayExpression($return->expr, $keyTypes, $valueTypes);
                    } elseif ($return->expr instanceof Node\Expr\Variable) {
                        // Try to trace the variable
                        $varName = $return->expr->name;
                        if (is_string($varName)) {
                            // Look for assignments to this variable
                            $this->analyzeVariableArrayAssignments($method, $varName, $keyTypes, $valueTypes);
                        }
                    }
                }

                $keyType = $this->determineType($keyTypes, 'int');
                $valueType = $this->determineType($valueTypes, 'mixed');

                return ['key' => $keyType, 'value' => $valueType];
            }

            /**
             * Find all return statements in a method
             * 
             * @param array<Node\Stmt\Return_> $returns
             */
            private function findReturnStatements(Node $node, array &$returns): void
            {
                if ($node instanceof Node\Stmt\Return_) {
                    $returns[] = $node;
                }

                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    if ($subNode instanceof Node) {
                        $this->findReturnStatements($subNode, $returns);
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node) {
                                $this->findReturnStatements($child, $returns);
                            }
                        }
                    }
                }
            }

            /**
             * Analyze array expression
             * 
             * @param array<string> $keyTypes
             * @param array<string> $valueTypes
             */
            private function analyzeArrayExpression(Node\Expr\Array_ $array, array &$keyTypes, array &$valueTypes): void
            {
                foreach ($array->items as $item) {

                    // Analyze key
                    if ($item->key !== null) {
                        $keyType = $this->inferExpressionType($item->key);
                        if ($keyType !== null) {
                            $keyTypes[] = $keyType;
                        }
                    } else {
                        $keyTypes[] = 'int';
                    }

                    // Analyze value
                    $valueType = $this->inferExpressionType($item->value);
                    if ($valueType !== null) {
                        $valueTypes[] = $valueType;
                    }
                }
            }

            /**
             * Infer type from expression
             */
            private function inferExpressionType(Node\Expr $expr): ?string
            {
                return match (true) {
                    $expr instanceof Node\Scalar\String_ => 'string',
                    $expr instanceof Node\Scalar\LNumber => 'int',
                    $expr instanceof Node\Scalar\DNumber => 'float',
                    $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'true' => 'bool',
                    $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'false' => 'bool',
                    $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null' => 'null',
                    $expr instanceof Node\Expr\Array_ => 'array',
                    default => null,
                };
            }

            /**
             * Analyze variable array assignments
             * 
             * @param array<string> $keyTypes
             * @param array<string> $valueTypes
             */
            private function analyzeVariableArrayAssignments(Node $scope, string $varName, array &$keyTypes, array &$valueTypes): void
            {
                // This is a simplified version - in practice, you'd want more sophisticated analysis
                // For now, we'll just look for direct array assignments
            }

            /**
             * Determine the most specific type
             * 
             * @param array<string> $types
             */
            private function determineType(array $types, string $default = 'mixed'): string
            {
                if (empty($types)) {
                    return $default;
                }

                $uniqueTypes = array_unique($types);
                if (count($uniqueTypes) === 1) {
                    return reset($uniqueTypes);
                }

                // For now, return union of top 2 most common types
                $typeCounts = array_count_values($types);
                arsort($typeCounts);
                $topTypes = array_slice(array_keys($typeCounts), 0, 2);
                
                return implode('|', $topTypes);
            }

            /**
             * Calculate the start position of the line containing the node
             * 
             * @param Node $node The node to find line start for
             * @return int The file position at the start of the line
             * @throws RuntimeException If unable to calculate line start
             */
            private function calculateLineStart(Node $node): int
            {
                try {
                    $nodePos = $node->getAttribute('startFilePos');
                    if ($nodePos === null) {
                        throw new \RuntimeException('Node does not have startFilePos attribute');
                    }

                    // Find the last newline before the node position
                    $contentBeforeNode = substr($this->originalContent, 0, $nodePos);
                    $lineStart = strrpos($contentBeforeNode, "\n");
                    
                    // If no newline found, we're at the beginning of the file
                    return ($lineStart === false) ? 0 : $lineStart + 1;
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Failed to calculate line start position: ' . $e->getMessage(), 0, $e);
                }
            }
        };

            $this->traverseWithVisitor($stmts, $visitor);

            // Validate that we have fixes to apply
            if (empty($visitor->fixes)) {
                throw new \RuntimeException('No fixes were generated for the given error');
            }

            // Sort fixes in reverse order (by start position descending) to avoid position shifting
            usort($visitor->fixes, function($a, $b) {
                return $b['start'] - $a['start'];
            });
            
            // Apply fixes with validation
            foreach ($visitor->fixes as $fix) {
                if ($fix['start'] < 0 || $fix['start'] > strlen($content)) {
                    throw new \RuntimeException('Invalid fix start position: ' . $fix['start']);
                }
                if ($fix['end'] < $fix['start'] || $fix['end'] > strlen($content)) {
                    throw new \RuntimeException('Invalid fix end position: ' . $fix['end']);
                }
                
                $content = substr($content, 0, $fix['start']) . $fix['text'] . substr($content, $fix['end']);
            }

            return $content;
            
        } catch (\Throwable $e) {
            // Log the error and return original content to prevent breaking the code
            error_log('MissingIterableValueTypeFixer error: ' . $e->getMessage());
            return $content; // Return original content on error
        }
    }
}