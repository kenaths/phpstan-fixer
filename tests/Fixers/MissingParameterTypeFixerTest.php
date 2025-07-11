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
}