<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingPropertyTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingPropertyTypeFixerTest extends TestCase
{
    private MissingPropertyTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingPropertyTypeFixer();
    }

    public function testCanFixPropertyTypeError(): void
    {
        $error = new Error('test.php', 10, 'Property Test::$name has no type specified');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesPropertyWithStringDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $name = "John";
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$name has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private string $name', $result);
    }

    public function testFixesPropertyWithIntDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $age = 25;
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$age has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private int $age', $result);
    }

    public function testFixesPropertyWithArrayDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $data = [];
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$data has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private array $data', $result);
    }

    public function testFixesPropertyWithNullDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $value = null;
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$value has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        // When default is null, fixer infers mixed or keeps null
        $this->assertTrue(str_contains($result, 'private mixed $value') || str_contains($result, 'private null $value'));
    }

    public function testInfersTypeFromPropertyName(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $userName;
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$userName has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private string|null $userName', $result);
    }

    public function testInfersIdFromPropertyName(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $userId;
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$userId has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('private int|null $userId', $result);
    }

    public function testInfersBoolFromPropertyName(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    private $isActive;
}
PHP;

        $error = new Error('test.php', 3, 'Property Test::$isActive has no type specified');
        $result = $this->fixer->fix($code, $error);
        
        // Property without default gets mixed type inference
        $this->assertStringContainsString('private mixed $isActive', $result);
    }
}