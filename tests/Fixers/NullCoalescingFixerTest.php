<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\NullCoalescingFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class NullCoalescingFixerTest extends TestCase
{
    private NullCoalescingFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new NullCoalescingFixer();
    }

    public function testCanFixNullCoalescingError(): void
    {
        $error = new Error('test.php', 10, 'isset() construct can be replaced with null coalesce operator');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testConvertsIssetToNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php
$result = isset($data['key']) ? $data['key'] : 'default';
PHP;

        $error = new Error('test.php', 2, 'isset() construct can be replaced with null coalesce operator');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$data[\'key\'] ?? \'default\'', $result);
    }

    public function testConvertsIssetTernaryToNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php
$name = isset($user['name']) ? $user['name'] : 'Anonymous';
PHP;

        $error = new Error('test.php', 2, 'isset() construct can be replaced with null coalesce operator');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$user[\'name\'] ?? \'Anonymous\'', $result);
    }

    public function testConvertsIssetPropertyToNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php
$value = isset($object->property) ? $object->property : null;
PHP;

        $error = new Error('test.php', 2, 'isset() construct can be replaced with null coalesce operator');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$object->property ?? null', $result);
    }

    public function testConvertsIssetVariableToNullCoalescing(): void
    {
        $code = <<<'PHP'
<?php
$result = isset($variable) ? $variable : 'fallback';
PHP;

        $error = new Error('test.php', 2, 'isset() construct can be replaced with null coalesce operator');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$variable ?? \'fallback\'', $result);
    }

    public function testHandlesComplexExpressions(): void
    {
        $code = <<<'PHP'
<?php
$config = isset($settings['database']['host']) ? $settings['database']['host'] : 'localhost';
PHP;

        $error = new Error('test.php', 2, 'isset() construct can be replaced with null coalesce operator');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$settings[\'database\'][\'host\'] ?? \'localhost\'', $result);
    }
}