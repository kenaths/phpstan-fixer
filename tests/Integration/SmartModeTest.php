<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Integration;

use PHPStanFixer\Cache\TypeCache;
use PHPStanFixer\PHPStanFixer;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPStanFixer\Parser\ErrorParser;
use PHPStanFixer\ValueObjects\Error;
use PHPUnit\Framework\TestCase;

class SmartModeTest extends TestCase
{
    private string $tempDir;
    private PHPStanFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpstan-fixer-smart-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        
        // Clean up any existing cache
        $cacheFile = $this->tempDir . '/.phpstan-fixer-cache.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }

    public function testMultiPassTypeImprovement(): void
    {
        // Create interconnected classes
        $this->createTestFile('ClassA.php', <<<'PHP'
<?php
namespace Test;

class ClassA 
{
    public $data;
    
    public function __construct()
    {
        $this->data = ['apple', 'banana', 'cherry'];
    }
}
PHP
        );

        $this->createTestFile('ClassB.php', <<<'PHP'
<?php
namespace Test;

class ClassB
{
    private $classA;
    
    public function __construct()
    {
        $this->classA = new ClassA();
    }
    
    public function setData($data)
    {
        $this->classA->data = $data;
    }
    
    public function getData()
    {
        return $this->classA->data;
    }
}
PHP
        );

        // Create mock PHPStan runner that simulates errors
        $mockRunner = $this->createMock(PHPStanRunner::class);
        
        // First pass: both classes have missing types
        $firstPassOutput = json_encode([
            'errors' => [
                [
                    'message' => 'Property Test\ClassA::$data has no type specified.',
                    'file' => $this->tempDir . '/ClassA.php',
                    'line' => 6
                ],
                [
                    'message' => 'Property Test\ClassB::$classA has no type specified.',
                    'file' => $this->tempDir . '/ClassB.php',
                    'line' => 6
                ],
                [
                    'message' => 'Method Test\ClassB::setData() has parameter $data with no type specified.',
                    'file' => $this->tempDir . '/ClassB.php',
                    'line' => 13
                ],
                [
                    'message' => 'Method Test\ClassB::getData() has no return type specified.',
                    'file' => $this->tempDir . '/ClassB.php',
                    'line' => 18
                ]
            ]
        ]);
        
        // Second pass: ClassA is fixed, now we can infer better types for ClassB
        $secondPassOutput = json_encode([
            'errors' => [
                [
                    'message' => 'Method Test\ClassB::setData() has parameter $data with no type specified.',
                    'file' => $this->tempDir . '/ClassB.php',
                    'line' => 13
                ],
                [
                    'message' => 'Method Test\ClassB::getData() has no return type specified.',
                    'file' => $this->tempDir . '/ClassB.php',
                    'line' => 18
                ]
            ]
        ]);
        
        // Third pass: all fixed
        $thirdPassOutput = json_encode(['errors' => []]);
        
        $mockRunner->expects($this->exactly(3))
            ->method('analyze')
            ->willReturnOnConsecutiveCalls($firstPassOutput, $secondPassOutput, $thirdPassOutput);
        
        $this->fixer = new PHPStanFixer($mockRunner);
        
        // Run fixer in smart mode
        $result = $this->fixer->fix([$this->tempDir], 1, [], false, true);
        
        // Verify results
        $this->assertEquals(4, $result->getFixedCount());
        $this->assertEquals(0, $result->getUnfixableCount());
        
        // Check that ClassA has the inferred array type
        $classAContent = file_get_contents($this->tempDir . '/ClassA.php');
        $this->assertStringContainsString('public array $data;', $classAContent);
        
        // Check that ClassB has proper types
        $classBContent = file_get_contents($this->tempDir . '/ClassB.php');
        $this->assertStringContainsString('private ClassA $classA;', $classBContent);
        $this->assertStringContainsString('public function setData(array $data)', $classBContent);
        $this->assertStringContainsString('public function getData(): array', $classBContent);
        
        // Verify cache was created
        $cacheFile = $this->tempDir . '/.phpstan-fixer-cache.json';
        $this->assertFileExists($cacheFile);
        
        $cache = json_decode(file_get_contents($cacheFile), true);
        $this->assertArrayHasKey('cache', $cache);
        $this->assertArrayHasKey('Test\\ClassA::$data', $cache['cache']);
    }

    public function testCircularDependencyHandling(): void
    {
        // Create classes with circular dependency
        $this->createTestFile('ServiceA.php', <<<'PHP'
<?php
namespace Test;

class ServiceA
{
    private $serviceB;
    
    public function setServiceB($service)
    {
        $this->serviceB = $service;
    }
    
    public function process($data)
    {
        return $this->serviceB->transform($data);
    }
}
PHP
        );

        $this->createTestFile('ServiceB.php', <<<'PHP'
<?php
namespace Test;

class ServiceB
{
    private $serviceA;
    
    public function setServiceA($service)
    {
        $this->serviceA = $service;
    }
    
    public function transform($input)
    {
        if (is_array($input)) {
            return implode(',', $input);
        }
        return (string) $input;
    }
}
PHP
        );

        // Mock PHPStan runner
        $mockRunner = $this->createMock(PHPStanRunner::class);
        
        $firstPassOutput = json_encode([
            'errors' => [
                [
                    'message' => 'Property Test\ServiceA::$serviceB has no type specified.',
                    'file' => $this->tempDir . '/ServiceA.php',
                    'line' => 6
                ],
                [
                    'message' => 'Property Test\ServiceB::$serviceA has no type specified.',
                    'file' => $this->tempDir . '/ServiceB.php',
                    'line' => 6
                ],
                [
                    'message' => 'Method Test\ServiceA::setServiceB() has parameter $service with no type specified.',
                    'file' => $this->tempDir . '/ServiceA.php',
                    'line' => 8
                ],
                [
                    'message' => 'Method Test\ServiceB::setServiceA() has parameter $service with no type specified.',
                    'file' => $this->tempDir . '/ServiceB.php',
                    'line' => 8
                ]
            ]
        ]);
        
        // After first pass, some types are resolved
        $secondPassOutput = json_encode([
            'errors' => []
        ]);
        
        $mockRunner->expects($this->exactly(2))
            ->method('analyze')
            ->willReturnOnConsecutiveCalls($firstPassOutput, $secondPassOutput);
        
        $this->fixer = new PHPStanFixer($mockRunner);
        
        // Run fixer in smart mode
        $result = $this->fixer->fix([$this->tempDir], 1, [], false, true);
        
        // Verify it handled circular dependencies without infinite loops
        $messages = $result->getMessages();
        $this->assertContains('All errors fixed in pass 2!', $messages);
        
        // Check that types were added
        $serviceAContent = file_get_contents($this->tempDir . '/ServiceA.php');
        $this->assertStringContainsString('private mixed $serviceB;', $serviceAContent);
        
        $serviceBContent = file_get_contents($this->tempDir . '/ServiceB.php');
        $this->assertStringContainsString('private mixed $serviceA;', $serviceBContent);
    }

    public function testCachePersistenceAcrossRuns(): void
    {
        // Create a class with a specific type
        $this->createTestFile('DataModel.php', <<<'PHP'
<?php
namespace Test;

class DataModel
{
    /**
     * @var array<int, string>
     */
    public array $items = ['first', 'second', 'third'];
}
PHP
        );

        // First run: analyze and cache the type
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')->willReturn(json_encode(['errors' => []]));
        
        $cache = new TypeCache($this->tempDir);
        $cache->setFilePathForClass('Test\\DataModel', $this->tempDir . '/DataModel.php');
        $cache->setPropertyType('Test\\DataModel', 'items', 'array<int, string>', 'array');
        $cache->save();
        
        // Create another class that uses DataModel
        $this->createTestFile('DataProcessor.php', <<<'PHP'
<?php
namespace Test;

class DataProcessor
{
    private $model;
    
    public function __construct()
    {
        $this->model = new DataModel();
    }
    
    public function processItems($items)
    {
        $this->model->items = $items;
    }
}
PHP
        );

        // Second run: should use cached type
        $secondRunOutput = json_encode([
            'errors' => [
                [
                    'message' => 'Property Test\DataProcessor::$model has no type specified.',
                    'file' => $this->tempDir . '/DataProcessor.php',
                    'line' => 6
                ],
                [
                    'message' => 'Method Test\DataProcessor::processItems() has parameter $items with no type specified.',
                    'file' => $this->tempDir . '/DataProcessor.php',
                    'line' => 13
                ]
            ]
        ]);
        
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')
            ->willReturnOnConsecutiveCalls($secondRunOutput, json_encode(['errors' => []]));
        
        $this->fixer = new PHPStanFixer($mockRunner);
        $result = $this->fixer->fix([$this->tempDir], 1, [], false, true);
        
        // Check that the cached type was used
        $content = file_get_contents($this->tempDir . '/DataProcessor.php');
        $this->assertStringContainsString('private DataModel $model;', $content);
        // The parameter should get array type from the cached property type
        $this->assertStringContainsString('public function processItems(array $items)', $content);
    }

    private function createTestFile(string $filename, string $content): void
    {
        file_put_contents($this->tempDir . '/' . $filename, $content);
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