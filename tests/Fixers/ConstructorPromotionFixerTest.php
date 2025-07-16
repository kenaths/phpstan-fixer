<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\ConstructorPromotionFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class ConstructorPromotionFixerTest extends TestCase
{
    private ConstructorPromotionFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new ConstructorPromotionFixer();
    }

    public function testCanFixConstructorPromotionError(): void
    {
        $error = new Error('test.php', 10, 'Constructor property promotion is not allowed');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testPromotesConstructorProperties(): void
    {
        $code = <<<'PHP'
<?php
class User {
    private string $name;
    private int $age;
    
    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age = $age;
    }
}
PHP;

        $error = new Error('test.php', 7, 'Constructor property promotion is not allowed');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('public function __construct(private string $name, private int $age)', $result);
        $this->assertStringNotContainsString('private string $name;', $result);
        $this->assertStringNotContainsString('$this->name = $name;', $result);
    }

    public function testHandlesPromotedPropertyTypes(): void
    {
        $code = <<<'PHP'
<?php
class Service {
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
    }
}
PHP;

        $error = new Error('test.php', 5, 'Promoted property must have a type');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('public function __construct(private Database $db)', $result);
    }

    public function testMaintainsNonPromotedCode(): void
    {
        $code = <<<'PHP'
<?php
class Service {
    private string $name;
    
    public function __construct(string $name)
    {
        $this->name = $name;
        $this->initialize();
    }
    
    private function initialize(): void
    {
        // Some initialization code
    }
}
PHP;

        $error = new Error('test.php', 5, 'Constructor property promotion is not allowed');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('$this->initialize();', $result);
    }
}