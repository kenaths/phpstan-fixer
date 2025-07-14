<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingReturnTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class MissingReturnTypeArrayTest extends TestCase
{
    private MissingReturnTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingReturnTypeFixer();
    }

    public function testFixesArrayReturnTypeWithStringKeys(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function rules(): array
    {
        return ['order' => 'required', 'email' => 'email'];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::rules() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        // Should add array return type
        $this->assertStringContainsString('public function rules(): array', $fixed);
        // Should add specific PHPDoc
        $this->assertStringContainsString('@return array<string, string>', $fixed);
    }

    public function testFixesArrayReturnTypeWithIntKeys(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getNumbers(): array
    {
        return [1, 2, 3, 4, 5];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getNumbers() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getNumbers(): array', $fixed);
        // For numeric arrays, use simple notation
        $this->assertStringContainsString('@return array<int>', $fixed);
    }

    public function testFixesArrayReturnTypeWithMixedValues(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getMixed(): array
    {
        return ['name' => 'John', 'age' => 30, 'active' => true];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getMixed() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getMixed(): array', $fixed);
        // Should detect mixed value types
        $this->assertStringContainsString('@return array<string, string|int|bool>', $fixed);
    }

    public function testFixesArrayReturnTypeFromVariable(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getData(): array
    {
        $data = ['foo' => 'bar', 'baz' => 'qux'];
        return $data;
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getData() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getData(): array', $fixed);
        $this->assertStringContainsString('@return array<string, string>', $fixed);
    }

    public function testFixesNestedArrayReturnType(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getConfig(): array
    {
        return [
            'database' => [
                'host' => 'localhost',
                'port' => 3306
            ]
        ];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getConfig() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getConfig(): array', $fixed);
        // Nested arrays should be detected as array values
        $this->assertStringContainsString('@return array<string, array>', $fixed);
    }

    public function testUpdatesExistingDocBlockWithArrayReturn(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return ['name' => 'required|string', 'email' => 'required|email'];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            6,
            'Method TestClass::rules() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        // Should preserve existing doc and add @return
        $this->assertStringContainsString('Get validation rules', $fixed);
        $this->assertStringContainsString('@return array<string, string>', $fixed);
    }

    public function testFixesArrayReturnWithExistingReturnType(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function rules(): array
    {
        return ['order' => 'required'];
    }
}
PHP;

        // This time the method already has array return type, but PHPStan wants specific types
        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::rules() should return array<string, string> but returns array.'
        );

        $fixed = $this->fixer->fix($code, $error);

        // Should not add another : array
        $this->assertEquals(1, substr_count($fixed, ': array'));
        // Should add PHPDoc with specific types
        $this->assertStringContainsString('@return array<string, string>', $fixed);
    }

    public function testFixesMultipleReturnStatements(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getData($type): array
    {
        if ($type === 'numbers') {
            return [1, 2, 3];
        }
        
        return ['a', 'b', 'c'];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getData() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getData($type): array', $fixed);
        // Should detect union of int and string values
        $this->assertStringContainsString('@return array<int|string>', $fixed);
    }

    public function testFixesEmptyArrayReturn(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function getEmpty(): array
    {
        return [];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::getEmpty() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        $this->assertStringContainsString('public function getEmpty(): array', $fixed);
        // Empty array should default to mixed
        $this->assertStringContainsString('@return array<mixed>', $fixed);
    }

    public function testPreservesIndentation(): void
    {
        $code = <<<'PHP'
<?php
class TestClass {
    public function rules(): array
    {
        return ['order' => 'required'];
    }
}
PHP;

        $error = new Error(
            __FILE__,
            3,
            'Method TestClass::rules() has no return type specified.'
        );

        $fixed = $this->fixer->fix($code, $error);

        // Check that indentation is preserved
        $lines = explode("\n", $fixed);
        foreach ($lines as $line) {
            if (strpos($line, '* @return') !== false) {
                // Debug the actual indentation
                $this->assertStringStartsWith('    * @return', $line);
            }
        }
    }
}