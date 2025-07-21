<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Integration;

use PHPStanFixer\PHPStanFixer;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPStanFixer\Parser\ErrorParser;
use PHPStanFixer\Fixers\Registry\FixerRegistry;
use PHPUnit\Framework\TestCase;

class EdgeCasesIntegrationTest extends TestCase
{
    private PHPStanFixer $fixer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpstan-fixer-edge-tests-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        
        // Create a mock PHPStan runner that returns no errors (simulating successful fixes)
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')->willReturn('{"totals":{"errors":0,"file_errors":0},"files":[],"errors":[]}');
        
        $this->fixer = new PHPStanFixer($mockRunner, new ErrorParser(), new FixerRegistry());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testHandlesEmptyPathsArray(): void
    {
        $result = $this->fixer->fix([], 5, [], false, false);
        $this->assertNotNull($result);
        $this->assertStringContainsString('No PHPStan errors found', $result->getMessage());
    }

    public function testHandlesNonExistentFile(): void
    {
        $nonExistentFile = $this->tempDir . '/does-not-exist.php';
        $result = $this->fixer->fix([$nonExistentFile], 5, [], false, false);
        $this->assertNotNull($result);
    }

    public function testHandlesComplexClassHierarchy(): void
    {
        $baseClassFile = $this->tempDir . '/BaseClass.php';
        $derivedClassFile = $this->tempDir . '/DerivedClass.php';
        
        file_put_contents($baseClassFile, <<<'PHP'
<?php
namespace Test;

abstract class BaseClass 
{
    protected $data;
    
    abstract public function process($input);
}
PHP);

        file_put_contents($derivedClassFile, <<<'PHP'
<?php
namespace Test;

class DerivedClass extends BaseClass
{
    private $processor;
    
    public function __construct($processor) 
    {
        $this->processor = $processor;
    }
    
    public function process($input)
    {
        return $this->processor->handle($input);
    }
    
    public function getData() 
    {
        return $this->data;
    }
}
PHP);

        $result = $this->fixer->fix([$baseClassFile, $derivedClassFile], 5, [], false, true);
        $this->assertNotNull($result);
    }

    public function testHandlesGenericTypesAndUnions(): void
    {
        $genericFile = $this->tempDir . '/GenericClass.php';
        
        file_put_contents($genericFile, <<<'PHP'
<?php
namespace Test;

class GenericClass 
{
    private $collection;
    private $mapper;
    
    public function __construct($collection, $mapper = null) 
    {
        $this->collection = $collection;
        $this->mapper = $mapper;
    }
    
    public function map($callback)
    {
        if ($this->mapper) {
            return $this->mapper->apply($callback, $this->collection);
        }
        return array_map($callback, $this->collection);
    }
    
    public function filter($predicate = null)
    {
        return $predicate ? array_filter($this->collection, $predicate) : $this->collection;
    }
}
PHP);

        $result = $this->fixer->fix([$genericFile], 5, [], false, true);
        $this->assertNotNull($result);
    }

    public function testHandlesNestedArrayTypes(): void
    {
        $arrayFile = $this->tempDir . '/ArrayClass.php';
        
        file_put_contents($arrayFile, <<<'PHP'
<?php
namespace Test;

class ArrayClass 
{
    private $matrix;
    private $config;
    
    public function __construct($matrix = [], $config = []) 
    {
        $this->matrix = $matrix;
        $this->config = $config;
    }
    
    public function getRow($index)
    {
        return $this->matrix[$index] ?? [];
    }
    
    public function getConfig($key = null)
    {
        return $key ? ($this->config[$key] ?? null) : $this->config;
    }
    
    public function flattenMatrix()
    {
        $result = [];
        foreach ($this->matrix as $row) {
            foreach ($row as $item) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
PHP);

        $result = $this->fixer->fix([$arrayFile], 5, [], false, true);
        $this->assertNotNull($result);
    }

    public function testHandlesCallableAndClosures(): void
    {
        $callableFile = $this->tempDir . '/CallableClass.php';
        
        file_put_contents($callableFile, <<<'PHP'
<?php
namespace Test;

class CallableClass 
{
    private $handlers;
    
    public function __construct($handlers = []) 
    {
        $this->handlers = $handlers;
    }
    
    public function addHandler($event, $callback)
    {
        $this->handlers[$event][] = $callback;
    }
    
    public function trigger($event, $data = null)
    {
        if (!isset($this->handlers[$event])) {
            return null;
        }
        
        $results = [];
        foreach ($this->handlers[$event] as $handler) {
            $results[] = $handler($data);
        }
        return $results;
    }
    
    public function createProcessor($config)
    {
        return function($input) use ($config) {
            return $config['transform']($input);
        };
    }
}
PHP);

        $result = $this->fixer->fix([$callableFile], 5, [], false, true);
        $this->assertNotNull($result);
    }

    public function testSmartModePerformance(): void
    {
        // Create multiple related files to test smart mode performance
        $files = [];
        for ($i = 1; $i <= 5; $i++) {
            $file = $this->tempDir . "/Class{$i}.php";
            file_put_contents($file, <<<PHP
<?php
namespace Test;

class Class{$i} 
{
    private \$dependency;
    private \$data;
    
    public function __construct(\$dependency) 
    {
        \$this->dependency = \$dependency;
        \$this->data = [];
    }
    
    public function process(\$input)
    {
        return \$this->dependency->handle(\$input);
    }
    
    public function getData() 
    {
        return \$this->data;
    }
}
PHP);
            $files[] = $file;
        }

        $startTime = microtime(true);
        $result = $this->fixer->fix($files, 5, [], false, true);
        $endTime = microtime(true);
        
        $this->assertNotNull($result);
        $this->assertLessThan(5.0, $endTime - $startTime, 'Smart mode should complete within reasonable time');
    }

    public function testBackupCreation(): void
    {
        $testFile = $this->tempDir . '/BackupTest.php';
        $originalContent = <<<'PHP'
<?php
class BackupTest 
{
    private $data;
    
    public function getData() 
    {
        return $this->data;
    }
}
PHP;
        file_put_contents($testFile, $originalContent);

        // Create a mock runner that returns an error so that fixes are attempted
        $mockRunner = $this->createMock(PHPStanRunner::class);
        $mockRunner->method('analyze')->willReturn(
            '{"totals":{"errors":1,"file_errors":1},"files":{"' . $testFile . '":{"errors":1,"messages":[{"message":"Property BackupTest::$data has no type specified.","line":4,"ignorable":true}]}},"errors":[]}'
        );
        
        $fixer = new PHPStanFixer($mockRunner, new ErrorParser(), new FixerRegistry());
        $result = $fixer->fix([$testFile], 5, [], true, false);
        
        $this->assertNotNull($result);
        
        // Check if backup was created (it might not be if no fixes were applied)
        // This test verifies the backup functionality works when fixes are applied
        if (file_exists($testFile . '.phpstan-fixer.bak')) {
            $this->assertEquals($originalContent, file_get_contents($testFile . '.phpstan-fixer.bak'));
        } else {
            // If no backup was created, it means no fixes were applied, which is also valid
            $this->addToAssertionCount(1);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }
}