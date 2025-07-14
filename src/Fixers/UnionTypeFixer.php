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
                        $node->returnType = $this->createUnionFromTypes($unionTypes);
                    }
                }

                if ($node instanceof Node\Param && abs($node->getLine() - $this->targetLine) < 3) {
                    // Handle parameter type unions
                    if (preg_match('/Parameter .* expects (.+) given/', $this->message, $matches)) {
                        $types = $this->parseTypeString($matches[1]);
                        if (count($types) > 1) {
                            $node->type = $this->createUnionFromTypes($types);
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
            private function createUnionFromTypes(array $types): Node\ComplexType|Node\Identifier|Node\Name
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
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}