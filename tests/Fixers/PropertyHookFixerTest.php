<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\PropertyHookFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class PropertyHookFixerTest extends TestCase
{
    private PropertyHookFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new PropertyHookFixer();
    }

    public function testCanFixPropertyHookError(): void
    {
        $error = new Error('test.php', 10, 'Property hook error');
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testFixesMissingBackingRead(): void
    {
        $code = <<<PHP
class Test {
    public int \$prop { get => 42; }
}
PHP;
        $error = new Error('test.php', 2, 'Backing value must be read in get hook');
        $result = $this->fixer->fix($code, $error);
        $this->assertStringContainsString('get => $this->prop;', $result);
    }

    public function testFixesMissingBackingAssign(): void
    {
        $code = <<<PHP
class Test {
    public int \$prop { set { /* no assign */ } }
}
PHP;
        $error = new Error('test.php', 2, 'Backing value must be assigned in set hook');
        $result = $this->fixer->fix($code, $error);
        $this->assertStringContainsString('$this->prop = $value;', $result);
    }
} 