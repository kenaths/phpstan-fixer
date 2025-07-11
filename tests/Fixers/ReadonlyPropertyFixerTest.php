<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\ReadonlyPropertyFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class ReadonlyPropertyFixerTest extends TestCase
{
    private ReadonlyPropertyFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new ReadonlyPropertyFixer();
    }

    public function testCanFixReadonlyPropertyError(): void
    {
        $error = new Error('test.php', 10, 'Cannot assign to readonly property Test::$id');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testFixesMissingPropertyTypeForReadonly(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private readonly $id;
    
    public function __construct($id)
    {
        $this->id = $id;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$id has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private readonly mixed $id;', $result);
    }

    public function testAddsTypeToProperty(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $name = "default";
    
    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$name has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private string $name = "default";', $result);
    }
}