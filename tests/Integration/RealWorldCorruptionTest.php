<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPStanFixer\PHPStanFixer;
use PHPStanFixer\ValueObjects\Error;

class RealWorldCorruptionTest extends TestCase
{
    public function testMultiSelectFilterWithMultipleErrors(): void
    {
        // Create a temporary file with the exact code
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

        $tempFile = tempnam(sys_get_temp_dir(), 'phpstan_fixer_test_') . '.php';
        file_put_contents($tempFile, $code);

        try {
            // Test with multiple potential errors that could happen
            $errors = [
                new Error($tempFile, 29, 'Method MultiSelectFilter::__construct() has parameter $options with no value type specified in iterable type array.'),
                new Error($tempFile, 15, 'Property MultiSelectFilter::$options has no value type specified in iterable type array.'),
                new Error($tempFile, 34, 'Method MultiSelectFilter::make() has parameter $options with no value type specified in iterable type array.'),
            ];

            $fixer = new PHPStanFixer();
            
            foreach ($errors as $error) {
                echo "\n=== PROCESSING ERROR: {$error->getMessage()} ===\n";
                
                $currentContent = file_get_contents($tempFile);
                echo "\n=== BEFORE FIX ===\n";
                echo $currentContent;
                echo "\n=== END BEFORE ===\n";
                
                // Get the appropriate fixer for this error
                $registry = new \PHPStanFixer\Fixers\Registry\FixerRegistry();
                $registry->register(new \PHPStanFixer\Fixers\MissingIterableValueTypeFixer());
                $registry->register(new \PHPStanFixer\Fixers\MissingPropertyTypeFixer());
                
                $singleFixer = $registry->getFixerForError($error);
                if ($singleFixer) {
                    $result = $singleFixer->fix($currentContent, $error);
                    file_put_contents($tempFile, $result);
                    
                    echo "\n=== AFTER FIX ===\n";
                    echo $result;
                    echo "\n=== END AFTER ===\n";
                    
                    // Check for corruption
                    if (strpos($result, 'parent::__con/**') !== false) {
                        echo "\n!!! CORRUPTION DETECTED AFTER FIXING: {$error->getMessage()} !!!\n";
                        $this->fail('Constructor corruption detected in result');
                    }
                } else {
                    echo "\n=== NO FIXER FOUND FOR ERROR ===\n";
                }
            }
            
            $finalContent = file_get_contents($tempFile);
            $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $finalContent);
            $this->assertStringNotContainsString('parent::__con/**', $finalContent);
            
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}