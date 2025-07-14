<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\UnionTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class UnionTypeFixerTest extends TestCase
{
    private UnionTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new UnionTypeFixer();
    }

    public function testCanFixUnionTypeError(): void
    {
        $error = new Error('test.php', 10, 'Method Test::getData() should return string|int but returns float');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testFixesMethodReturningUnionType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function getData($type)
    {
        if ($type === 'string') {
            return "hello";
        } elseif ($type === 'int') {
            return 42;
        }
        return 3.14;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::getData() should return string|int but returns float');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertMatchesRegularExpression('/getData\\s*\\(.*?\\)\\s*:\\s*string\\|int\\|float/', $result);
    }

    public function testFixesNullableUnionType(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function process($data)
    {
        if ($data === null) {
            return null;
        }
        return is_string($data) ? $data : (string) $data;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Method Test::process() should return string but returns null');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertMatchesRegularExpression('/process\\s*\\(.*?\\)\\s*:\\s*\\?string/', $result);
    }

    public function testDoesNotFixClosureParameter(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    protected function allLabelsCanceled(Shipment $shipment): bool
    {
        $shipment->loadMissing('labels');

        return $shipment->labels->every(fn (Label $label) => $label->canceled_at !== null);
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter #1 $shipment of method Test::allLabelsCanceled() expects Shipment, string given');
        $result = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('allLabelsCanceled(Shipment $shipment): bool', $result);
        $this->assertStringContainsString('fn (Label $label) => $label->canceled_at !== null', $result);
        $this->assertDoesNotMatchRegularExpression('/fn \(.*?\|.*?\) =>/', $result);
    }
}