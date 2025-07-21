<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\DocBlockFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class DocBlockFixerTest extends TestCase
{
    private DocBlockFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new DocBlockFixer();
    }

    public function testCanFixDocBlockError(): void
    {
        $error = new Error('test.php', 10, 'PHPDoc tag @param has invalid');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesIncorrectParamOrder(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    /**
     * @param $name string
     */
    public function setName($name)
    {
    }
}
PHP;

        $expected = <<<'PHP'
<?php
class Test {
    /**
     * @param string $name
     */
    public function setName($name)
    {
    }
}
PHP;

        $error = new Error('test.php', 4, 'PHPDoc tag @param has invalid format');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@param string $name', $result);
    }

    public function testFixesEmptyReturnTag(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    /**
     * @return
     */
    public function getData()
    {
        return [];
    }
}
PHP;

        $error = new Error('test.php', 4, 'PHPDoc tag @return has invalid format');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@return mixed', $result);
    }
}