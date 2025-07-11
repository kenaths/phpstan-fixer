<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes property hook related errors (PHP 8.4 feature)
 * Currently handles basic property access patterns
 */
class PropertyHookFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['property_hook', 'property_access'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match(
            '/property.*hook|Cannot access property|Property.*does not exist/',
            $error->getMessage()
        );
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract property information from error
        preg_match('/(?:Property|property) ([^:]+)::(\$?\w+)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = str_replace('$', '', $matches[2] ?? '');

        $visitor = new class($className, $propertyName, $error->getLine()) extends NodeVisitorAbstract {
            private string $className;
            private string $propertyName;
            private int $targetLine;
            private ?Node\Stmt\Class_ $currentClass = null;

            public function __construct(string $className, string $propertyName, int $targetLine)
            {
                $this->className = $className;
                $this->propertyName = $propertyName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->currentClass = $node;
                }

                // Check if we need to add the property
                if ($this->currentClass !== null 
                    && $node instanceof Node\Stmt\Class_
                    && ($node->name === null || $node->name->toString() === $this->className)) {
                    
                    $hasProperty = false;
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\Property) {
                            foreach ($stmt->props as $prop) {
                                if ($prop->name->toString() === $this->propertyName) {
                                    $hasProperty = true;
                                    break 2;
                                }
                            }
                        }
                    }

                    if (!$hasProperty) {
                        // Add the missing property
                        $property = new Node\Stmt\Property(
                            Node\Stmt\Class_::MODIFIER_PRIVATE,
                            [new Node\Stmt\PropertyProperty($this->propertyName)],
                            [],
                            new Node\Identifier('mixed')
                        );

                        // Add at the beginning of the class
                        array_unshift($node->stmts, $property);
                    }
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->currentClass = null;
                }
                return null;
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}