<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

class PropertyHookFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['property_hook_error'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Backing value of non-virtual property must be read in get hook/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        // Extract property info
        preg_match('/Property (.*?)::\$(\w+)/', $error->getMessage(), $matches);
        $className = $matches[1] ?? '';
        $propertyName = $matches[2] ?? '';

        if (!$className || !$propertyName) {
            return $content;
        }

        $nodeFinder = new NodeFinder();

        // Find the class
        $class = $nodeFinder->findFirst($stmts, function(Node $node) use ($className) {
            return $node instanceof Node\Stmt\Class_ && $node->name->name === $className;
        });

        if (!$class) {
            return $content;
        }

        // Find the property
        $property = $nodeFinder->findFirst($class->stmts, function(Node $node) use ($propertyName) {
            return $node instanceof Node\Stmt\Property && $node->props[0]->name->name === $propertyName;
        });

        if (!$property || !$property->getAttribute('hooks')) {
            return $content;
        }

        // Find get hook
        $getHook = null;
        foreach ($property->getAttribute('hooks') as $hook) {
            if ($hook instanceof Node\Stmt\PropertyHook && $hook->type === 'get') {
                $getHook = $hook;
                break;
            }
        }

        if (!$getHook) {
            return $content;
        }

        // Check if already returns the backing value
        $returnsBacking = $nodeFinder->findFirst($getHook->stmts, function(Node $node) use ($propertyName) {
            return $node instanceof Return_ &&
                   $node->expr instanceof PropertyFetch &&
                   $node->expr->var instanceof Variable &&
                   $node->expr->var->name === 'this' &&
                   $node->expr->name->name === $propertyName;
        });

        if ($returnsBacking) {
            return $content;
        }

        // Add return statement
        $returnStmt = new Return_(new PropertyFetch(new Variable('this'), $propertyName));
        $getHook->stmts[] = $returnStmt;

        return $this->printCode($stmts);
    }
} 