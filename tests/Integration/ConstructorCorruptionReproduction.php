<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPStanFixer\Fixers\MissingIterableValueTypeFixer;
use PHPStanFixer\ValueObjects\Error;

class ConstructorCorruptionReproduction extends TestCase
{
    public function testReproduceExactUserIssue(): void
    {
        $code = '<?php

namespace EcommerceGeeks\LaravelInertiaTables\Components\Table;

use Closure;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * @description A filter that allows the user to filter by multiple values
 */
class MultiSelectFilter extends Filter
{
    protected string $type = \'MultiSelect\';

    protected array $options;

    protected ?Closure $customFilterClosure = null;

    public function usingCustomFilter(Closure $closure): self
    {
        $this->customFilterClosure = $closure;

        return $this;
    }

    public function __construct(string $name, string $label, string $alias = \'\', array $options = [])
    {
        parent::__construct($name, $label, $alias);
        $this->options = $options;
    }

    public static function make(string $name, string $label, string $alias = \'\', array $options = []): MultiSelectFilter
    {
        return new MultiSelectFilter($name, $label, $alias, $options);
    }

    public function getAllowedFilter(): AllowedFilter
    {
        if ($this->customFilterClosure) {
            return call_user_func($this->customFilterClosure, $this);
        }

        return AllowedFilter::callback(
            $this->getPublicName(),
            function ($query, $values) {
                if (! is_array($values)) {
                    $values = [$values];
                }
                $query->where(function ($query) use ($values) {
                    foreach ($values as $value) {
                        $query->orWhere($this->getInternalName(), \'=\', $value);
                    }
                });
            },
            $this->getInternalName()
        );
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}';

        $fixer = new MissingIterableValueTypeFixer();
        $error = new Error('test.php', 29, 'Method MultiSelectFilter::__construct() has parameter $options with no value type specified in iterable type array.');

        echo "\n=== ORIGINAL CODE ===\n";
        echo $code;
        echo "\n=== END ORIGINAL ===\n";

        $result = $fixer->fix($code, $error);

        echo "\n=== FIXED CODE ===\n";
        echo $result;
        echo "\n=== END FIXED ===\n";

        // Check if corruption occurred
        if (strpos($result, 'parent::__con/**') !== false) {
            echo "\n!!! CORRUPTION DETECTED !!!\n";
            $this->fail('Constructor corruption detected in result');
        }

        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result);
        $this->assertStringNotContainsString('parent::__con/**', $result);
    }
}