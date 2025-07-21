<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\UndefinedVariableFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class UndefinedVariableFixerTest extends TestCase
{
    private UndefinedVariableFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new UndefinedVariableFixer();
    }

    public function testCanFixUndefinedVariableError(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Property Test::$name has no type specified');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testInitializesUndefinedVariableInFunction(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    echo $undefined;
}
PHP;

        $error = new Error('test.php', 3, 'Undefined variable: $undefined');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$undefined = null;', $result);
    }

    public function testInitializesUndefinedVariableInMethod(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function process() {
        return $result;
    }
}
PHP;

        $error = new Error('test.php', 4, 'Undefined variable: $result');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$result = null;', $result);
    }

    public function testInitializesVariableBeforeUsage(): void
    {
        $code = <<<'PHP'
<?php
function calculate() {
    $sum = $value + 10;
    return $sum;
}
PHP;

        $error = new Error('test.php', 3, 'Undefined variable: $value');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$value = null;', $result);
        $this->assertStringContainsString('$sum = $value + 10;', $result);
    }

    public function testHandlesComplexMethodWithUndefinedVariable(): void
    {
        $code = <<<'PHP'
<?php
class Service {
    public function getData() {
        if ($condition) {
            $data = "test";
        }
        return $data;
    }
}
PHP;

        $error = new Error('test.php', 7, 'Undefined variable: $data');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$data = null;', $result);
    }

    public function testDoesNotInitializeAlreadyDefinedVariable(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $defined = "value";
    echo $defined;
}
PHP;

        $error = new Error('test.php', 4, 'Undefined variable: $defined');
        $result = $this->fixer->fix($code, $error);
        
        // Should not add another initialization
        $this->assertEquals(1, substr_count($result, '$defined = null;'));
    }
}