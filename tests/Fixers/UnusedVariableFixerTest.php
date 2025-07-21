<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\UnusedVariableFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class UnusedVariableFixerTest extends TestCase
{
    private UnusedVariableFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new UnusedVariableFixer();
    }

    public function testCanFixUnusedVariableError(): void
    {
        $error = new Error('test.php', 10, 'Variable $unused is never used');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Property Test::$name has no type specified');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testRemovesUnusedVariableAssignment(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $unused = "value";
    echo "Hello";
}
PHP;

        $error = new Error('test.php', 3, 'Variable $unused is never used');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringNotContainsString('$unused = "value";', $result);
        $this->assertStringContainsString('echo "Hello";', $result);
    }

    public function testRemovesUnusedVariableInMethod(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function process() {
        $temp = "simple value";
        return "done";
    }
}
PHP;

        $error = new Error('test.php', 4, 'Variable $temp is never used');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringNotContainsString('$temp = "simple value";', $result);
    }

    public function testDoesNotRemoveAssignmentWithSideEffects(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $result = doSomething();
    echo "done";
}
PHP;

        $error = new Error('test.php', 3, 'Variable $result is never used');
        $result = $this->fixer->fix($code, $error);
        
        // Should not remove because doSomething() might have side effects
        $this->assertStringContainsString('$result = doSomething();', $result);
    }

    public function testDoesNotRemoveMethodCallWithSideEffects(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function process() {
        $data = $this->performAction();
        return "success";
    }
    
    private function performAction() {
        return "action";
    }
}
PHP;

        $error = new Error('test.php', 4, 'Variable $data is never used');
        $result = $this->fixer->fix($code, $error);
        
        // Should not remove because method call might have side effects
        $this->assertStringContainsString('$data = $this->performAction();', $result);
    }

    public function testRemovesSimpleValueAssignment(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $number = 42;
    $string = "hello";
    $array = [1, 2, 3];
    echo "working";
}
PHP;

        $error = new Error('test.php', 3, 'Variable $number is never used');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringNotContainsString('$number = 42;', $result);
        $this->assertStringContainsString('$string = "hello";', $result);
        $this->assertStringContainsString('echo "working";', $result);
    }

    public function testHandlesNestedAssignments(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $outer = "test";
    if (true) {
        $inner = "nested";
    }
    echo "done";
}
PHP;

        $error = new Error('test.php', 4, 'Variable $inner is never used');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringNotContainsString('$inner = "nested";', $result);
        $this->assertStringContainsString('$outer = "test";', $result);
    }
}