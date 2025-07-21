<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPStanFixer\Fixers\MissingIterableValueTypeFixer;
use PHPStanFixer\Fixers\MissingParameterTypeFixer;
use PHPStanFixer\Fixers\MissingGenericParameterFixer;
use PHPStanFixer\ValueObjects\Error;

class DocBlockCorruptionTest extends TestCase
{
    private MissingIterableValueTypeFixer $fixer;

    protected function setUp(): void
    {
        $this->fixer = new MissingIterableValueTypeFixer();
    }

    public function testConstructorDoesNotGetCorrupted(): void
    {
        $code = <<<'PHP'
<?php

class TestClass extends BaseClass
{
    private array $options;

    public function __construct(string $name, string $label, string $alias = '', array $options = [])
    {
        parent::__construct($name, $label, $alias);
        $this->options = $options;
    }
}
PHP;

        $error = new Error('test.php', 7, 'Method TestClass::__construct() has parameter $options with no value type specified in iterable type array.');

        $result = $this->fixer->fix($code, $error);

        // The parent::__construct call should remain intact
        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result);
        
        // Should not contain corrupted construct call
        $this->assertStringNotContainsString('parent::__con/**', $result);
        $this->assertStringNotContainsString('*/struct(', $result);
        
        // Should contain the docblock for the parameter
        $this->assertStringContainsString('@param array<mixed> $options', $result);
    }

    public function testSpecificBrokenCase(): void
    {
        // Test the exact case reported by the user
        $code = <<<'PHP'
<?php

class TestClass extends BaseClass
{
    private array $options;

    public function __construct(string $name, string $label, string $alias = '', array $options = [])
    {
        parent::__construct($name, $label, $alias);
        $this->options = $options;
    }
}
PHP;

        $error = new Error('test.php', 7, 'Method TestClass::__construct() has parameter $options with no value type specified in iterable type array.');

        $result = $this->fixer->fix($code, $error);
        
        // Verify the exact issue doesn't occur
        $lines = explode("\n", $result);
        
        foreach ($lines as $line) {
            // No line should contain the broken pattern
            $this->assertStringNotContainsString('parent::__con/**', $line);
            $this->assertStringNotContainsString('*/struct(', $line);
        }
        
        // Ensure parent::__construct remains correctly formed
        $hasCorrectConstruct = false;
        foreach ($lines as $line) {
            if (strpos($line, 'parent::__construct(') !== false) {
                $hasCorrectConstruct = true;
                // Should be a complete, well-formed call
                $this->assertMatchesRegularExpression('/parent::__construct\([^)]*\);/', $line);
            }
        }
        
        $this->assertTrue($hasCorrectConstruct, 'Should contain a properly formed parent::__construct call');
    }

    public function testParameterTypeFixerDoesNotCorrupt(): void
    {
        $fixer = new MissingParameterTypeFixer();
        
        $code = <<<'PHP'
<?php

class TestClass extends BaseClass
{
    private array $options;

    public function __construct($name, $label, $alias = '', array $options = [])
    {
        parent::__construct($name, $label, $alias);
        $this->options = $options;
    }
}
PHP;

        $error = new Error('test.php', 7, 'Parameter $name of method TestClass::__construct() has no type specified.');

        $result = $fixer->fix($code, $error);

        // The parent::__construct call should remain intact
        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result);
        
        // Should not contain corrupted construct call
        $this->assertStringNotContainsString('parent::__con/**', $result);
        $this->assertStringNotContainsString('*/struct(', $result);
        
        // Debug: print actual result if something goes wrong
        if (strpos($result, 'parent::__con/**') !== false) {
            echo "\n=== CORRUPTED CODE DETECTED ===\n";
            echo $result;
            echo "\n=== END ===\n";
        }
    }

    public function testGenericParameterFixerDoesNotCorrupt(): void
    {
        $fixer = new MissingGenericParameterFixer();
        
        $code = <<<'PHP'
<?php

use Illuminate\Support\Collection;

class TestClass extends BaseClass
{
    private array $options;

    public function __construct(string $name, string $label, string $alias = '', Collection $collection = null)
    {
        parent::__construct($name, $label, $alias);
        $this->options = [];
    }
}
PHP;

        $error = new Error('test.php', 9, 'Method TestClass::__construct() has parameter $collection with generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue');

        $result = $fixer->fix($code, $error);

        // The parent::__construct call should remain intact
        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result);
        
        // Should not contain corrupted construct call
        $this->assertStringNotContainsString('parent::__con/**', $result);
        $this->assertStringNotContainsString('*/struct(', $result);
        
        // Should contain the docblock for the parameter
        $this->assertStringContainsString('@param Collection<', $result);
        
        // Debug: print actual result if something goes wrong
        if (strpos($result, 'parent::__con/**') !== false) {
            echo "\n=== CORRUPTED CODE DETECTED ===\n";
            echo $result;
            echo "\n=== END ===\n";
        }
    }

    public function testExactUserReportedIssue(): void
    {
        // Test the exact scenario reported by the user
        $code = <<<'PHP'
<?php

class TestClass extends BaseClass
{
    private array $options;

    public function __construct(string $name, string $label, string $alias = '', array $options = [])
    {
        parent::__construct($name, $label, $alias);
        $this->options = $options;
    }
}
PHP;

        // Test with MissingIterableValueTypeFixer (uses string replacement)
        $iterableFixer = new MissingIterableValueTypeFixer();
        $error1 = new Error('test.php', 7, 'Method TestClass::__construct() has parameter $options with no value type specified in iterable type array.');
        $result1 = $iterableFixer->fix($code, $error1);
        
        // Verify no corruption with string replacement approach
        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result1);
        $this->assertStringNotContainsString('parent::__con/**', $result1);
        
        // Test with MissingGenericParameterFixer (now uses formatting-preserving pretty printer)
        $genericFixer = new MissingGenericParameterFixer();
        
        // Modify code to have a generic parameter that would trigger this fixer
        $codeWithGeneric = str_replace('array $options = []', 'Collection $options = null', $code);
        $codeWithGeneric = "<?php\n\nuse Illuminate\\Support\\Collection;\n" . substr($codeWithGeneric, 5);
        
        $error2 = new Error('test.php', 9, 'Method TestClass::__construct() has parameter $options with generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue');
        $result2 = $genericFixer->fix($codeWithGeneric, $error2);
        
        // Verify no corruption with formatting-preserving approach
        $this->assertStringContainsString('parent::__construct($name, $label, $alias);', $result2);
        $this->assertStringNotContainsString('parent::__con/**', $result2);
        $this->assertStringNotContainsString('*/struct(', $result2);
        
        // Ensure the DocBlock was added correctly
        $this->assertStringContainsString('@param Collection<', $result2);
    }
}