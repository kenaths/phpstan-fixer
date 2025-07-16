<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests;

use PHPStanFixer\PHPStanFixer;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPUnit\Framework\TestCase;

class PHPStanFixerIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpstan-fixer-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFixesMultipleErrorsInFile(): void
    {
        $testFile = $this->tempDir . '/TestClass.php';
        $code = <<<'PHP'
<?php
class TestClass {
    private $property;
    
    public function method($param)
    {
        if ($param == null) {
            return;
        }
        
        $unused = "test";
        
        return $param;
    }
}
PHP;

        file_put_contents($testFile, $code);

        // Mock the PHPStan runner to return predefined errors
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')->willReturn(json_encode([
            'files' => [
                $testFile => [
                    'messages' => [
                        ['line' => 3, 'message' => 'Property TestClass::$property has no type specified.'],
                        ['line' => 5, 'message' => 'Method TestClass::method() has no return type specified.'],
                        ['line' => 5, 'message' => 'Parameter $param of method TestClass::method() has no type specified.'],
                        ['line' => 7, 'message' => 'Strict comparison using === between $param and null'],
                        ['line' => 11, 'message' => 'Variable $unused is never used.'],
                    ]
                ]
            ]
        ]));

        $fixer = new PHPStanFixer($mockRunner);
        $result = $fixer->fix([$testFile], 5, [], true);

        // At least 3 errors should be fixed
        $this->assertGreaterThanOrEqual(3, $result->getFixedCount());
        $this->assertEquals(0, $result->getUnfixableCount());

        // Check that backup was created
        $this->assertFileExists($testFile . '.phpstan-fixer.bak');

        // Verify the fixed code - check what actually gets fixed
        $fixedCode = file_get_contents($testFile);
        $this->assertStringContainsString('private mixed $property;', $fixedCode);
        $this->assertStringContainsString('$param === null', $fixedCode);
        $this->assertStringNotContainsString('$unused = "test";', $fixedCode);
    }

    public function testPreservesFormatting(): void
    {
        $testFile = $this->tempDir . '/FormattedClass.php';
        $code = <<<'PHP'
<?php

class FormattedClass {

    public $property = [
        'key1' => 'value1',
        'key2' => 'value2',
    ];
    
    // Some comment
    
}
PHP;

        file_put_contents($testFile, $code);

        // Mock error for missing property type
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')->willReturnOnConsecutiveCalls(
            json_encode([
                'files' => [
                    $testFile => [
                        'messages' => [
                            ['line' => 5, 'message' => 'Property FormattedClass::$property has no type specified.'],
                        ]
                    ]
                ]
            ]),
            json_encode(['files' => []]) // After fix, no errors
        );

        $fixer = new PHPStanFixer($mockRunner);
        $fixer->fix([$testFile], 6, [], true);

        $fixedCode = file_get_contents($testFile);

        // Check if type was added (could be basic array or generic array)
        $this->assertTrue(
            str_contains($fixedCode, 'public array $property') || 
            str_contains($fixedCode, 'public array<string, string> $property'),
            'Expected either "public array $property" or "public array<string, string> $property" but found: ' . $fixedCode
        );

        // Check if multi-line array preserved
        $this->assertStringContainsString("[
        'key1' => 'value1',
        'key2' => 'value2',
    ]", $fixedCode);

        // Check empty lines preserved
        $this->assertEquals(substr_count($code, "\n\n"), substr_count($fixedCode, "\n\n"));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}