<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes union type errors in PHP 8.0+
 */
class UnionTypeFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['union_type_error', 'incompatible_return_type', 'incompatible_param_type'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Union type|expects .* given|should return .* but returns/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($error->getLine(), $error->getMessage()) extends NodeVisitorAbstract {
            private int $targetLine;
            private string $message;

            public ?array $fix = null;

            public function __construct(int $targetLine, string $message)
            {
                $this->targetLine = $targetLine;
                $this->message = $message;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod && abs($node->getLine() - $this->targetLine) < 5) {
                    // Handle return type unions
                    if (preg_match('/Method .* should return (.+) but returns (.+)/', $this->message, $matches)) {
                        $expectedTypes = $this->parseTypeString($matches[1]);
                        $actualTypes = $this->parseTypeString($matches[2]);
                        
                        $unionTypes = array_unique(array_merge($expectedTypes, $actualTypes));
                        $typeNode = $this->createUnionFromTypes($unionTypes);
                        $typeStr = $this->typeToString($typeNode);
                        $insertionStart = $node->name->getAttribute('endFilePos') + 1;
                        if (!empty($node->params)) {
                            $last = end($node->params);
                            $insertionStart = $last->getAttribute('endFilePos') + 1;
                        }
                        $this->fix = ['kind' => 'return', 'start' => $insertionStart, 'type' => $typeStr];
                    }
                }

                if ($node instanceof Node\Param && abs($node->getLine() - $this->targetLine) < 3) {
                    // Handle parameter type unions
                    if (preg_match('/Parameter #\\d+ \\$(\\w+) .* expects (.+) given/', $this->message, $matches)) {
                        $paramName = $matches[1];
                        $expected = $matches[2];
                        if ($node->var instanceof Node\Expr\Variable && $node->var->name === $paramName) {
                            $types = $this->parseTypeString($expected);
                            if (count($types) > 1) {
                                $typeNode = $this->createUnionFromTypes($types);
                                $typeStr = $this->typeToString($typeNode);
                                $insertionPos = $node->var->getAttribute('startFilePos');
                                $this->fix = ['kind' => 'param', 'pos' => $insertionPos, 'type' => $typeStr];
                            }
                        }
                    }
                }

                return null;
            }

            /**
             * @return array<string>
             */
            private function parseTypeString(string $typeString): array
            {
                // Handle complex type strings like "string|int|null"
                $typeString = trim($typeString);
                $types = [];

                if (str_contains($typeString, '|')) {
                    $types = explode('|', $typeString);
                } else {
                    $types = [$typeString];
                }

                return array_map('trim', $types);
            }

            /**
             * @param array<string> $types
             */
            private function createUnionFromTypes(array $types): Node\ComplexType|Node\Name
            {
                $nodeTypes = [];
                $hasNull = false;

                foreach ($types as $type) {
                    if ($type === 'null') {
                        $hasNull = true;
                    } else {
                        $nodeTypes[] = new Node\Name($type);
                    }
                }

                if (count($nodeTypes) === 1 && $hasNull) {
                    // Create nullable type instead of union with null
                    return new Node\NullableType($nodeTypes[0]);
                }

                if ($hasNull) {
                    $nodeTypes[] = new Node\Name('null');
                }

                if (count($nodeTypes) === 1) {
                    return $nodeTypes[0];
                }

                return new Node\UnionType($nodeTypes);
            }

            private function typeToString(Node $typeNode): string
            {
                if ($typeNode instanceof Node\Name) return $typeNode->toString();
                if ($typeNode instanceof Node\NullableType) return '?' . $this->typeToString($typeNode->type);
                if ($typeNode instanceof Node\UnionType) return implode('|', array_map([$this, 'typeToString'], $typeNode->types));
                return 'mixed';
            }
        };

        $this->traverseWithVisitor($stmts, $visitor);

        if ($visitor->fix !== null) {
            $fix = $visitor->fix;
            if ($fix['kind'] === 'param') {
                $pos = $fix['pos'];
                $text = $fix['type'] . ' ';
                $content = substr($content, 0, $pos) . $text . substr($content, $pos);
            } elseif ($fix['kind'] === 'return') {
                $start = $fix['start'];
                $parenPos = strpos($content, ')', $start);
                if ($parenPos !== false) {
                    $pos = $parenPos + 1;
                    while (isset($content[$pos]) && ctype_space($content[$pos])) {
                        $pos++;
                    }
                    $text = ': ' . $fix['type'];
                    $content = substr($content, 0, $pos) . $text . substr($content, $pos);
                }
            }
        }

        return $content;
    }
}