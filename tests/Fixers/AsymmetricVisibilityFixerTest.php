<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\AsymmetricVisibilityFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class AsymmetricVisibilityFixerTest extends TestCase
{
    private AsymmetricVisibilityFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new AsymmetricVisibilityFixer();
    }

    public function testCanFixAsymmetricError(): void
    {
        $error = new Error('test.php', 10, 'Asymmetric visibility not allowed');
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testAddsPrivateSet(): void
    {
        $code = <<<PHP
class Test {
    public int \$prop;
}
PHP;
        $error = new Error('test.php', 2, 'Asymmetric visibility error for Test::\$prop');
        $result = $this->fixer->fix($code, $error);
        $this->assertStringContainsString('public private(set) int $prop;', $result);
    }
} 