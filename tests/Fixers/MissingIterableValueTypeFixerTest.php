<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingIterableValueTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingIterableValueTypeFixerTest extends TestCase
{
    private MissingIterableValueTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingIterableValueTypeFixer();
    }

    public function testCanFixIterablePropertyError(): void
    {
        $error = new Error('test.php', 10, 'Property Test::$items has no value type specified in iterable type array.');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCanFixIterableMethodReturnError(): void
    {
        $error = new Error('test.php', 10, 'Method Test::getItems() return type has no value type specified in iterable type array.');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testFixesPropertyMissingIterableType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private array $items = [];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$items has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        // Debug: print the result to see what's happening
        // var_dump($result);
        
        $this->assertStringContainsString('@var array<mixed>', $result);
    }

    public function testFixesMethodReturnMissingIterableType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getItems(): array
    {
        return [];
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getItems() return type has no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@return array<mixed>', $result);
    }

    public function testFixesParameterMissingIterableType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setItems(array $items): void
    {
        $this->items = $items;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::setItems() has parameter $items with no value type specified in iterable type array.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@param array<mixed> $items', $result);
    }
}