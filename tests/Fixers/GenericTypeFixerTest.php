<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\GenericTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPUnit\Framework\TestCase;

class GenericTypeFixerTest extends TestCase
{
    private GenericTypeFixer $fixer;
    private PHPStanRunner $mockRunner;

    protected function setUp(): void
    {
        $this->mockRunner = $this->createMock(PHPStanRunner::class);
        $this->fixer = new GenericTypeFixer($this->mockRunner);
    }

    public function testCanFixGenericClassError(): void
    {
        $error = new Error(
            'test.php',
            10,
            'Class ColumnCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCanFixGenericInterfaceError(): void
    {
        $error = new Error(
            'test.php',
            10,
            'Class MyClass implements generic interface Iterator but does not specify its types: TKey, TValue'
        );
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testCannotFixOtherErrors(): void
    {
        $error = new Error('test.php', 10, 'Undefined variable: $test');
        
        $this->assertFalse($this->fixer->canFix($error));
    }

    public function testFixesBasicGenericClassExtension(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ColumnCollection extends Collection
{
    public function getFirst(): ?Column
    {
        return $this->first();
    }
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class ColumnCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Collection<int, Column>', $result);
        $this->assertStringContainsString('class ColumnCollection extends Collection', $result);
    }

    public function testFixesGenericInterfaceImplementation(): void
    {
        $code = <<<'PHP'
<?php
class MyIterator implements Iterator
{
    public function current(): mixed
    {
        return null;
    }
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Class MyIterator implements generic interface Iterator but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Iterator<int, mixed>', $result);
    }

    public function testWithPHPStanFeedbackRefinement(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ColumnCollection extends Collection
{
    public function getDefaultSortByColumn(): ?Column
    {
        return $this->first(function ($column) {
            return $column->isDefaultSortBy();
        });
    }
}
PHP;

        // Mock PHPStan feedback that tells us the actual type
        $this->mockRunner->method('analyze')->willReturn(json_encode([
            'files' => [
                'temp_file' => [
                    'messages' => [
                        [
                            'line' => 6,
                            'message' => 'Method ColumnCollection::getDefaultSortByColumn() should return Column|null but returns mixed.',
                            'identifier' => 'return.type'
                        ]
                    ]
                ]
            ]
        ]));

        $error = new Error(
            'test.php',
            4,
            'Class ColumnCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        // Should refine the type from mixed to Column based on PHPStan feedback
        $this->assertStringContainsString('@template-extends Collection<int, Column>', $result);
    }

    public function testHandlesMultipleTypeParameters(): void
    {
        $code = <<<'PHP'
<?php
class MyMap extends GenericMap
{
    public function get(string $key): ?Value
    {
        return parent::get($key);
    }
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Class MyMap extends generic class GenericMap but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends GenericMap<int, Value>', $result);
    }

    public function testExtractsClassInfoFromErrorMessage(): void
    {
        $fixer = new GenericTypeFixer(); // Without PHPStan runner
        
        $code = <<<'PHP'
<?php
class TestCollection extends Collection
{
    public function test(): void
    {
    }
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Class TestCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $fixer->fix($code, $error);
        
        $this->assertStringContainsString('/**', $result);
        $this->assertStringContainsString('@template-extends Collection<int, mixed>', $result);
        $this->assertStringContainsString('*/', $result);
    }

    public function testHandlesShortClassNames(): void
    {
        $code = <<<'PHP'
<?php
use Some\Namespace\BaseCollection;

class MyCollection extends BaseCollection
{
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class MyCollection extends generic class Some\Namespace\BaseCollection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends BaseCollection<int, mixed>', $result);
    }

    public function testHandlesComplexTypeInference(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class UserCollection extends Collection
{
    public function getActiveUsers(): UserCollection
    {
        return $this->filter(function ($user) {
            return $user->isActive();
        });
    }
    
    public function findByEmail(string $email): ?User
    {
        return $this->first(function ($user) use ($email) {
            return $user->email === $email;
        });
    }
}
PHP;

        // Mock complex PHPStan feedback
        $this->mockRunner->method('analyze')->willReturn(json_encode([
            'files' => [
                'temp_file' => [
                    'messages' => [
                        [
                            'line' => 10,
                            'message' => 'Method UserCollection::findByEmail() should return User|null but returns mixed.',
                            'identifier' => 'return.type'
                        ],
                        [
                            'line' => 6,
                            'message' => 'Parameter #1 $callback of method Collection::filter() expects callable(User, int): bool, callable(mixed, int): bool given.',
                            'identifier' => 'argument.type'
                        ]
                    ]
                ]
            ]
        ]));

        $error = new Error(
            'test.php',
            4,
            'Class UserCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Collection<int, User>', $result);
    }

    public function testHandlesNoPhpstanRunner(): void
    {
        $fixer = new GenericTypeFixer(); // No PHPStan runner
        
        $code = <<<'PHP'
<?php
class TestCollection extends Collection
{
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Class TestCollection extends generic class Collection but does not specify its types: TKey, TValue'
        );

        $result = $fixer->fix($code, $error);
        
        // Should still add basic generic types
        $this->assertStringContainsString('@template-extends Collection<int, mixed>', $result);
    }

    public function testHandlesInvalidErrorMessage(): void
    {
        $code = <<<'PHP'
<?php
class TestClass
{
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Some unrelated error message'
        );

        $result = $this->fixer->fix($code, $error);
        
        // Should return unchanged content
        $this->assertEquals($code, $result);
    }

    public function testSupportedTypes(): void
    {
        $supportedTypes = $this->fixer->getSupportedTypes();
        
        $this->assertContains('missingType.generics', $supportedTypes);
        $this->assertContains('generic.missingType', $supportedTypes);
        $this->assertContains('class.extendsGenericClassWithoutTypes', $supportedTypes);
    }

    public function testRefinementIterations(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ProductCollection extends Collection
{
    public function getExpensiveProducts(): ProductCollection
    {
        return $this->filter(function ($product) {
            return $product->price > 100;
        });
    }
}
PHP;

        // Mock iterative PHPStan feedback
        $this->mockRunner->method('analyze')->willReturnOnConsecutiveCalls(
            // First call - basic type mismatch
            json_encode([
                'files' => [
                    'temp_file' => [
                        'messages' => [
                            [
                                'line' => 7,
                                'message' => 'Parameter #1 $callback of method Collection::filter() expects callable(Product, int): bool, callable(mixed, int): bool given.',
                                'identifier' => 'argument.type'
                            ]
                        ]
                    ]
                ]
            ]),
            // Second call - no more errors
            json_encode(['files' => []])
        );

        $error = new Error(
            'test.php',
            4,
            'Class ProductCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Collection<int, Product>', $result);
    }
}