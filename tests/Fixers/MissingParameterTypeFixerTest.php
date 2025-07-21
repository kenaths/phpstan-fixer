<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingParameterTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingParameterTypeFixerTest extends TestCase
{
    private MissingParameterTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingParameterTypeFixer();
    }

    public function testCanFixParameterTypeError(): void
    {
        $error = new Error('test.php', 10, 'Parameter $param of method Test::process() has no type specified.');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCanFixAlternateErrorMessage(): void
    {
        $error = new Error('test.php', 10, 'Method Test::process() has parameter $param with no type specified.');
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesParameterWithDefaultString(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setName($name = "default")
    {
        $this->name = $name;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $name of method Test::setName() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setName(string $name = "default")', $result);
    }

    public function testFixesParameterWithDefaultInt(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setAge($age = 0)
    {
        $this->age = $age;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $age of method Test::setAge() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setAge(int $age = 0)', $result);
    }

    public function testFixesParameterWithoutDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function process($data)
    {
        return $data;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $data of method Test::process() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('process(mixed $data)', $result);
    }

    public function testFixesAlternateErrorMessage(): void
    {
        $code = <<<'PHP'
<?php
class Column {
    public function __construct($name)
    {
        $this->name = $name;
    }
}
PHP;

        $errorMsg = 'Method Column::__construct() has parameter $name with no type specified.';
        $error = new Error('column.php', 3, $errorMsg);
        $result = $this->fixer->fix($code, $error);
        $this->assertStringContainsString('__construct(string $name)', $result);
    }

    public function testInfersTypeFromParameterName(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setUserName($userName)
    {
        $this->userName = $userName;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $userName of method Test::setUserName() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setUserName(string $userName)', $result);
    }

    public function testInfersTypeFromParameterNameWithId(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setUserId($userId)
    {
        $this->userId = $userId;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $userId of method Test::setUserId() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setUserId(int $userId)', $result);
    }

    public function testInfersTypeFromParameterNameWithIs(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setEnabled($isEnabled)
    {
        $this->isEnabled = $isEnabled;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $isEnabled of method Test::setEnabled() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setEnabled(bool $isEnabled)', $result);
    }

    public function testHandlesNullDefault(): void
    {
        $code = <<<'PHP'
<?php
class Test {
    public function setName($name = null)
    {
        $this->name = $name;
    }
}
PHP;

        $error = new Error('test.php', 3, 'Parameter $name of method Test::setName() has no type specified.');
        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('setName(string|null $name = null)', $result);
    }
}