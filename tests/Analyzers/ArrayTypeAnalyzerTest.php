<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStanFixer\Analyzers\ArrayTypeAnalyzer;
use PHPUnit\Framework\TestCase;

class ArrayTypeAnalyzerTest extends TestCase
{
    private ArrayTypeAnalyzer $analyzer;
    private $parser;
    private NodeFinder $nodeFinder;

    protected function setUp(): void
    {
        $this->analyzer = new ArrayTypeAnalyzer();
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function testAnalyzeNumericArray(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $numbers = [1, 2, 3, 4, 5];
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'numbers');
        
        $this->assertEquals('int', $result['key']);
        $this->assertEquals('int', $result['value']);
    }

    public function testAnalyzeStringArray(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $names = ['John', 'Jane', 'Bob'];
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'names');
        
        $this->assertEquals('int', $result['key']);
        $this->assertEquals('string', $result['value']);
    }

    public function testAnalyzeAssociativeArray(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $config = [
        'host' => 'localhost',
        'port' => '3306',
        'username' => 'root'
    ];
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'config');
        
        $this->assertEquals('string', $result['key']);
        $this->assertEquals('string', $result['value']);
    }

    public function testAnalyzeMixedValueArray(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $mixed = [
        'name' => 'John',
        'age' => 30,
        'active' => true
    ];
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'mixed');
        
        $this->assertEquals('string', $result['key']);
        $this->assertContains($result['value'], ['string|int', 'int|string', 'string|bool', 'bool|string']);
    }

    public function testAnalyzeArrayFromMethodUsage(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $data;
    
    public function process(): void
    {
        $this->data[0] = 'first';
        $this->data[1] = 'second';
        $this->data[2] = 'third';
    }
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        $method = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\ClassMethod::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'data', $method);
        
        $this->assertEquals('int', $result['key']);
        $this->assertEquals('string', $result['value']);
    }

    public function testAnalyzeArrayWithStringKeys(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $map;
    
    public function build(): void
    {
        $this->map['first'] = 1;
        $this->map['second'] = 2;
        $this->map['third'] = 3;
    }
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        $method = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\ClassMethod::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'map', $method);
        
        $this->assertEquals('string', $result['key']);
        $this->assertEquals('int', $result['value']);
    }

    public function testAnalyzeArrayFromForeach(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $items;
    
    public function process(): void
    {
        foreach ($this->items as $key => $value) {
            $trimmed = trim($value);
            $length = strlen($key);
        }
    }
}
PHP;

        $stmts = $this->parser->parse($code);
        $property = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Property::class);
        $method = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\ClassMethod::class);
        
        $result = $this->analyzer->analyzeArrayType($property, 'items', $method);
        
        $this->assertEquals('string', $result['key']);
        $this->assertEquals('string', $result['value']);
    }
}