<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\StrictComparisonFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class StrictComparisonFixerTest extends TestCase
{
    private StrictComparisonFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new StrictComparisonFixer();
    }

    public function testFixesLooseEquality(): void
    {
        $code = <<<'PHP'
<?php
if ($value == null) {
    return false;
}
PHP;

        $error = new Error('test.php', 2, 'Strict comparison using === between $value and null');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$value === null', $result);
    }

    public function testFixesLooseInequality(): void
    {
        $code = <<<'PHP'
<?php
if ($count != 0) {
    echo "Has items";
}
PHP;

        $error = new Error('test.php', 2, 'Strict comparison using !== between $count and 0');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$count !== 0', $result);
    }
}