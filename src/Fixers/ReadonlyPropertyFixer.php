<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes readonly property errors in PHP 8.1+
 */
class ReadonlyPropertyFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['readonly_property_write', 'missing_property_type'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/readonly property|Property .* has no type specified/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract property info from error message
        preg_match('/Property (.*?)::\$(\w+)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        $visitor = new class($propertyName, $error->getLine(), $error->getMessage()) extends NodeVisitorAbstract {
            private string $propertyName;
            private int $targetLine;
            private string $message;

            public function __construct(string $propertyName, int $targetLine, string $message)
            {
                $this->propertyName = $propertyName;
                $this->targetLine = $targetLine;
                $this->message = $message;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Property && abs($node->getLine() - $this->targetLine) < 3) {
                    foreach ($node->props as $prop) {
                        if ($prop instanceof Node\Stmt\PropertyProperty 
                            && $prop->name->toString() === $this->propertyName) {
                            
                            // Add type if missing
                            if ($node->type === null) {
                                $type = $this->inferPropertyType($prop, $node);
                                if ($type !== null) {
                                    $node->type = $type;
                                }
                            }

                            // Handle readonly property write errors
                            if (str_contains($this->message, 'Cannot assign to readonly property')) {
                                // Check if property should be readonly based on usage
                                if ($this->shouldBeReadonly($node)) {
                                    $node->flags |= Node\Stmt\Class_::MODIFIER_READONLY;
                                }
                            }
                        }
                    }
                }
                
                return null;
            }

            private function inferPropertyType(Node\Stmt\PropertyProperty $prop, Node\Stmt\Property $property): ?Node
            {
                // If property has a default value, infer from it
                if ($prop->default !== null) {
                    return $this->inferTypeFromValue($prop->default);
                }

                // Check if property is already readonly
                if ($property->isReadonly()) {
                    // Readonly properties must have a type
                    return new Node\Name('mixed');
                }

                // For modern PHP, prefer explicit types
                return new Node\Name('mixed');
            }

            private function inferTypeFromValue(Node $node): ?Node
            {
                return match (true) {
                    $node instanceof Node\Scalar\String_ => new Node\Name('string'),
                    $node instanceof Node\Scalar\LNumber => new Node\Name('int'),
                    $node instanceof Node\Scalar\DNumber => new Node\Name('float'),
                    $node instanceof Node\Expr\Array_ => new Node\Name('array'),
                    $node instanceof Node\Expr\ConstFetch && $node->name->toLowerString() === 'null' => new Node\Name('?mixed'),
                    $node instanceof Node\Expr\ConstFetch && in_array($node->name->toLowerString(), ['true', 'false']) => new Node\Name('bool'),
                    default => new Node\Name('mixed'),
                };
            }

            private function shouldBeReadonly(Node\Stmt\Property $property): bool
            {
                // Simple heuristic: if property is private and has no setter, it could be readonly
                return $property->isPrivate() && !$property->isStatic();
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}