<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingIterableValueTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingIterableValueTypeFixerAdvancedTest extends TestCase
{
    private MissingIterableValueTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingIterableValueTypeFixer();
    }

    public function testInfersIntArrayType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $numbers = [1, 2, 3, 4, 5];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$numbers has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@var array<int>', $result);
        $this->assertStringNotContainsString('@var array<mixed>', $result);
    }

    public function testInfersStringArrayType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $names = ['John', 'Jane', 'Bob'];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$names has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@var array<string>', $result);
    }

    public function testInfersAssociativeArrayType(): void
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

        $error = new Error('test.php', 3, 'Property Test::$config has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@var array<string, string>', $result);
    }

    public function testInfersMixedAssociativeArrayType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $user = [
        'name' => 'John Doe',
        'age' => 30,
        'active' => true
    ];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$user has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        // Should detect string keys and mixed values
        $this->assertStringContainsString('@var array<string,', $result);
        $this->assertMatchesRegularExpression('/@var array<string, (string\|int|int\|string|bool\|string|string\|bool)>/', $result);
    }

    public function testInfersArrayTypeFromMethodReturn(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getScores(): array
    {
        return [
            'math' => 95,
            'science' => 88,
            'english' => 92
        ];
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getScores() return type has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@return array<string, int>', $result);
    }

    public function testInfersArrayTypeFromMultipleReturns(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getData(bool $numeric): array
    {
        if ($numeric) {
            return [1, 2, 3, 4, 5];
        }
        return ['a', 'b', 'c'];
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getData() return type has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        // Should detect union type
        $this->assertMatchesRegularExpression('/@return array<(int\|string|string\|int)>/', $result);
    }

    public function testInfersEmptyArrayAsMixed(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $empty = [];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$empty has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@var array<mixed>', $result);
    }

    public function testPreservesExistingDocBlock(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    /**
     * List of items
     */
    private array $items = ['apple', 'banana', 'orange'];
}
PHP;

        $error = new Error('test.php', 6, 'Property Test::$items has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('List of items', $result);
        $this->assertStringContainsString('@var array<string>', $result);
    }
}