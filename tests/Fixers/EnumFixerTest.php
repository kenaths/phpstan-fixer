<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\EnumFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class EnumFixerTest extends TestCase
{
    private EnumFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new EnumFixer();
    }

    public function testCanFixEnumError(): void
    {
        $error = new Error('test.php', 10, 'Enum case Status::ACTIVE does not exist');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesEnumBackingType(): void
    {
        $code = <<<'PHP'
<?php
enum Status {
    case ACTIVE;
    case INACTIVE;
}
PHP;

        $expected = <<<'PHP'
<?php
enum Status: string {
    case ACTIVE;
    case INACTIVE;
}
PHP;

        $error = new Error('test.php', 2, 'Enum backing type must be string');
        $result = $this->fixer->fix($code, $error);
        
        // Check that backing type was added (formatting may vary)
        $this->assertTrue(str_contains($result, 'enum Status: string') || str_contains($result, 'enum Status : string'));
    }

    public function testFixesEnumCaseTypo(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

class Service {
    public function check(): Status
    {
        return Status::ACTIV;
    }
}
PHP;

        $error = new Error('test.php', 9, 'Enum case Status::ACTIV does not exist');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('Status::ACTIVE', $result);
    }

    public function testAddsMissingEnumCasesForStatus(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case ACTIVE = 'active';
}
PHP;

        $error = new Error('test.php', 2, 'Enum Status must implement all cases');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('case PENDING', $result);
        $this->assertStringContainsString('case COMPLETED', $result);
    }
}