<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\GenericTypeFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class GenericTypeStructuralInferenceTest extends TestCase
{
    private GenericTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new GenericTypeFixer(); // No PHPStan runner - uses only structural inference
    }

    public function testInfersColumnTypeFromReturnTypeDeclaration(): void
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

        $error = new Error(
            'test.php',
            4,
            'Class ColumnCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Collection<int, Column>', $result);
    }

    public function testInfersColumnTypeFromMethodCallPatterns(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ColumnCollection extends Collection
{
    public function hasSearchableColumn(): bool
    {
        return (bool) $this->first(function ($column) {
            return $column->getSearchable();
        });
    }

    public function getDefaultSortByColumn(): ?Column
    {
        if (!$column = $this->first(function ($column) {
            return $column->isDefaultSortBy();
        })) {
            return $column = $this->first(function ($column) {
                return $column->isSortable();
            });
        }
        return $column;
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
    }

    public function testInfersUserTypeFromMethodName(): void
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
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class UserCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        // Should infer User from the return type UserCollection
        $this->assertStringContainsString('@template-extends Collection<int, UserCollection>', $result);
    }

    public function testInfersProductTypeFromMethodCalls(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ProductCollection extends Collection
{
    public function getExpensiveProducts(): ProductCollection
    {
        return $this->filter(function ($product) {
            return $product->getPrice() > 100;
        });
    }
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class ProductCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        // Should infer ProductCollection from the return type
        $this->assertStringContainsString('@template-extends Collection<int, ProductCollection>', $result);
    }

    public function testFallsBackToMixedWhenNoTypeCanBeInferred(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class GenericCollection extends Collection
{
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class GenericCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Collection<int, mixed>', $result);
    }

    public function testHandlesComplexNestedStructures(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Collection;

class ColumnCollection extends Collection
{
    public function hasSearchableColumn(): bool
    {
        return (bool) $this->first(function ($column) {
            return $column->getSearchable();
        });
    }

    public function getDefaultSortByColumn(): ?Column
    {
        if (!$column = $this->first(function ($column) {
            return $column->isDefaultSortBy();
        })) {
            return $column = $this->first(function ($column) {
                return $column->isSortable();
            });
        }
        return $column;
    }

    public function getSortableColumns(): self
    {
        return $this->filter(function ($column) {
            return $column->isSortable();
        });
    }
}
PHP;

        $error = new Error(
            'test.php',
            4,
            'Class ColumnCollection extends generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        // Should infer Column from multiple method patterns
        $this->assertStringContainsString('@template-extends Collection<int, Column>', $result);
    }

    public function testHandlesInterfaceImplementation(): void
    {
        $code = <<<'PHP'
<?php
class MyIterator implements Iterator
{
    public function current(): Column
    {
        return $this->items[$this->position];
    }

    public function getColumns(): array
    {
        return $this->items;
    }
}
PHP;

        $error = new Error(
            'test.php',
            2,
            'Class MyIterator implements generic interface Iterator but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@template-extends Iterator<int, Column>', $result);
    }
}