<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingReturnTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingReturnTypeFixerTest extends TestCase
{
    private MissingReturnTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingReturnTypeFixer();
    }

    public function testCanFixReturnTypeError(): void
    {
        $error = new Error('test.php', 10, 'Method Test::getData() has no return type specified.');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesMethodReturningString(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getMessage()
    {
        return "Hello, World!";
    }
}
PHP;

        $expected = <<<'PHP'
<?php
class Test {
    public function getMessage(): string
    {
        return "Hello, World!";
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getMessage() has no return type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertMatchesRegularExpression('/getMessage\\s*\\(\\)\\s*:\\s*string/', $result);
    }

    public function testFixesMethodReturningVoid(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function doSomething()
    {
        echo "Doing something";
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::doSomething() has no return type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertMatchesRegularExpression('/doSomething\\s*\\(\\)\\s*:\\s*void/', $result);
    }

    public function testFixesMethodReturningArray(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getData()
    {
        return ['a', 'b', 'c'];
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getData() has no return type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertMatchesRegularExpression('/getData\\s*\\(\\)\\s*:\\s*array/', $result);
    }
}