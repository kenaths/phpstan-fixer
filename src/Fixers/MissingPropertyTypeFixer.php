<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class MissingPropertyTypeFixer extends AbstractFixer
{
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

        // Extract property info from error message
        preg_match('/Property (.*?)::\$(\w+) has no type specified/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        $visitor = new class($propertyName, $error->getLine()) extends NodeVisitorAbstract {
            private string $propertyName;
            private int $targetLine;

            public function __construct(string $propertyName, int $targetLine)
            {
                $this->propertyName = $propertyName;
                $this->targetLine = $targetLine;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\Property
                    && abs($node->getLine() - $this->targetLine) < 3) {
                    
                    foreach ($node->props as $prop) {
                        if ($prop instanceof Node\PropertyItem
                            && $prop->name->toString() === $this->propertyName
                            && $node->type === null) {
                            
                            // Try to infer type from default value
                            $type = $this->inferPropertyType($prop);
                            if ($type !== null) {
                                $node->type = $type;
                            }
                        }
                    }
                }
                
                return null;
            }

            private function inferPropertyType(Node\PropertyItem $prop): Node\Name
            {
                if ($prop->default !== null) {
                    if ($prop->default instanceof Node\Scalar\String_) {
                        return new Node\Name('string');
                    }
                    if ($prop->default instanceof Node\Scalar\LNumber) {
                        return new Node\Name('int');
                    }
                    if ($prop->default instanceof Node\Scalar\DNumber) {
                        return new Node\Name('float');
                    }
                    if ($prop->default instanceof Node\Expr\Array_) {
                        return new Node\Name('array');
                    }
                    if ($prop->default instanceof Node\Expr\ConstFetch) {
                        $name = $prop->default->name->toLowerString();
                        if ($name === 'true' || $name === 'false') {
                            return new Node\Name('bool');
                        }
                        if ($name === 'null') {
                            return new Node\Name('?mixed');
                        }
                    }
                }

                return new Node\Name('mixed');
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}