<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\{Error, Lexer, NodeTraverser, NodeVisitor, Parser, ParserFactory, PrettyPrinter};
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\ValueObjects\Error as PHPStanError;

/**
 * Abstract base fixer with enhanced PHP 8.4 support
 */
abstract class AbstractFixer implements FixerInterface
{
    protected Parser $parser;
    protected PrettyPrinter\Standard $printer;
    protected NodeFinder $nodeFinder;
    protected Lexer $lexer;

    public function __construct()
    {
        $this->lexer = new Lexer\Emulative([
            'usedAttributes' => [
                'comments',
                'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
            ],
        ]);
        
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new PrettyPrinter\Standard([
            'shortArraySyntax' => true,
            'phpVersion' => 80400, // PHP 8.4
        ]);
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @return array<\PhpParser\Node\Stmt>|null
     */
    protected function parseCode(string $code): ?array
    {
        try {
            return $this->parser->parse($code);
        } catch (Error $error) {
            return null;
        }
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     */
    protected function printCode(array $stmts): string
    {
        return $this->printer->prettyPrintFile($stmts);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $stmts
     * @return array<\PhpParser\Node\Stmt>
     */
    protected function traverseWithVisitor(array $stmts, NodeVisitor $visitor): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        return $traverser->traverse($stmts);
    }

    /**
     * Add method to safely get node types
     */
    protected function getNodeTypes(Node $node): ?array
    {
        if (isset($node->types)) {
            return $node->types;
        }
        return null;
    }

    /**
     * Find nodes of a specific type
     * 
     * @param array<\PhpParser\Node> $stmts
     * @return array<\PhpParser\Node>
     */
    protected function findNodes(array $stmts, string $nodeClass): array
    {
        return $this->nodeFinder->findInstanceOf($stmts, $nodeClass);
    }

    /**
     * Find first node of a specific type
     * 
     * @param array<\PhpParser\Node> $stmts
     */
    protected function findFirstNode(array $stmts, string $nodeClass): ?Node
    {
        return $this->nodeFinder->findFirstInstanceOf($stmts, $nodeClass);
    }

    /**
     * Check if a type is a union type
     */
    protected function isUnionType(Node $type): bool
    {
        return $type instanceof Node\UnionType;
    }

    /**
     * Check if a type is an intersection type
     */
    protected function isIntersectionType(Node $type): bool
    {
        return $type instanceof Node\IntersectionType;
    }

    /**
     * Check if a type is a DNF (Disjunctive Normal Form) type
     */
    protected function isDNFType(Node $type): bool
    {
        if (!$this->isUnionType($type)) {
            return false;
        }

        foreach ($type->types as $subType) {
            if ($this->isIntersectionType($subType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a union type node
     * 
     * @param array<\PhpParser\Node> $types
     */
    protected function createUnionType(array $types): Node\UnionType
    {
        $validTypes = [];
        foreach ($types as $type) {
            if ($type instanceof Node\Name || $type instanceof Node\Identifier || $type instanceof Node\IntersectionType) {
                $validTypes[] = $type;
            }
        }
        return new Node\UnionType($validTypes);
    }

    /**
     * Create an intersection type node
     * 
     * @param array<\PhpParser\Node> $types
     */
    protected function createIntersectionType(array $types): Node\IntersectionType
    {
        $validTypes = [];
        foreach ($types as $type) {
            if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
                $validTypes[] = $type;
            }
        }
        return new Node\IntersectionType($validTypes);
    }

    /**
     * Create a nullable type node
     */
    protected function createNullableType(Node|string $type): Node\NullableType
    {
        if (is_string($type)) {
            $type = new Node\Name($type);
        }
        return new Node\NullableType($type);
    }

    /**
     * Check if the code uses readonly properties
     */
    protected function hasReadonlyProperties(array $stmts): bool
    {
        $properties = $this->findNodes($stmts, Node\Stmt\Property::class);
        foreach ($properties as $property) {
            if ($property instanceof Node\Stmt\Property && $property->isReadonly()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the code uses constructor property promotion
     */
    protected function hasConstructorPromotion(array $stmts): bool
    {
        $constructors = $this->findNodes($stmts, Node\Stmt\ClassMethod::class);
        foreach ($constructors as $method) {
            if ($method->name->name === '__construct') {
                foreach ($method->params as $param) {
                    if ($param->flags !== 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Get PHP version specific type name
     */
    protected function getTypeForPHPVersion(string $type): Node\Name|Node\UnionType|Node\IntersectionType
    {
        // Handle PHP 8+ types
        return match ($type) {
            'mixed' => new Node\Name('mixed'),
            'never' => new Node\Name('never'),
            'null' => new Node\Name('null'),
            'true' => new Node\Name('true'),
            'false' => new Node\Name('false'),
            default => new Node\Name($type),
        };
    }

    /**
     * Infer type from a value node with PHP 8.4 support
     */
    protected function inferTypeFromValue(Node $node): ?Node
    {
        return match (true) {
            $node instanceof Node\Scalar\String_ => new Node\Name('string'),
            $node instanceof Node\Scalar\LNumber => new Node\Name('int'),
            $node instanceof Node\Scalar\DNumber => new Node\Name('float'),
            $node instanceof Node\Expr\Array_ => new Node\Name('array'),
            $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name => $node->class,
            $node instanceof Node\Expr\ConstFetch => $this->inferTypeFromConstant($node),
            $node instanceof Node\Expr\ClassConstFetch => $this->inferTypeFromClassConstant($node),
            default => null,
        };
    }

    /**
     * Infer type from constant
     */
    private function inferTypeFromConstant(Node\Expr\ConstFetch $node): ?Node
    {
        $name = $node->name->toLowerString();
        return match ($name) {
            'true', 'false' => new Node\Name('bool'),
            'null' => new Node\Name('null'),
            default => null,
        };
    }

    /**
     * Infer type from class constant
     */
    private function inferTypeFromClassConstant(Node\Expr\ClassConstFetch $node): ?Node
    {
        if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $className = $node->class->toString();
            $constName = $node->name->toString();
            
            // Handle enum cases
            if ($constName !== 'class') {
                // This might be an enum case
                return new Node\Name($className);
            }
        }
        
        return new Node\Name('string'); // ::class returns string
    }
}