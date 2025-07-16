<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Cache;

use PHPStanFixer\Cache\TypeCache;
use PHPUnit\Framework\TestCase;

class TypeCacheTest extends TestCase
{
    private string $tempDir;
    private TypeCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpstan-fixer-test-' . uniqid();
        mkdir($this->tempDir);
        $this->cache = new TypeCache($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }

    public function testSetAndGetPropertyType(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/User.php';
        file_put_contents($testFile, '<?php class User {}');
        
        $this->cache->setFilePathForClass('App\\Models\\User', $testFile);
        $this->cache->setPropertyType('App\\Models\\User', 'name', 'string', 'string');
        
        $type = $this->cache->getPropertyType('App\\Models\\User', 'name');
        
        $this->assertNotNull($type);
        $this->assertEquals('string', $type['phpDoc']);
        $this->assertEquals('string', $type['native']);
    }

    public function testSetAndGetMethodTypes(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/DataProcessor.php';
        file_put_contents($testFile, '<?php class DataProcessor {}');
        
        $this->cache->setFilePathForClass('App\\Services\\DataProcessor', $testFile);
        
        $paramTypes = [
            'data' => ['phpDoc' => 'array<int, string>', 'native' => 'array'],
            'validate' => ['phpDoc' => 'bool', 'native' => 'bool'],
        ];
        
        $this->cache->setMethodTypes(
            'App\\Services\\DataProcessor',
            'process',
            $paramTypes,
            'void',
            'void'
        );
        
        $returnType = $this->cache->getMethodReturnType('App\\Services\\DataProcessor', 'process');
        $this->assertNotNull($returnType);
        $this->assertEquals('void', $returnType['native']);
        
        $methodParams = $this->cache->getMethodParameterTypes('App\\Services\\DataProcessor', 'process');
        $this->assertNotNull($methodParams);
        $this->assertArrayHasKey('data', $methodParams);
        $this->assertEquals('array<int, string>', $methodParams['data']['phpDoc']);
    }

    public function testCachePersistence(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/Product.php';
        file_put_contents($testFile, '<?php class Product {}');
        
        $this->cache->setFilePathForClass('App\\Models\\Product', $testFile);
        $this->cache->setPropertyType('App\\Models\\Product', 'price', 'float', 'float');
        $this->cache->save();
        
        // Create a new cache instance
        $newCache = new TypeCache($this->tempDir);
        $newCache->setFilePathForClass('App\\Models\\Product', $testFile);
        
        $type = $newCache->getPropertyType('App\\Models\\Product', 'price');
        $this->assertNotNull($type);
        $this->assertEquals('float', $type['phpDoc']);
    }

    public function testClearCache(): void
    {
        $this->cache->setPropertyType('App\\Models\\Order', 'total', 'float');
        $this->cache->save();
        
        $this->cache->clear();
        
        $type = $this->cache->getPropertyType('App\\Models\\Order', 'total');
        $this->assertNull($type);
        
        // Verify cache file is deleted
        $this->assertFileDoesNotExist($this->tempDir . '/.phpstan-fixer-cache.json');
    }

    public function testHandleClassNameVariations(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/User.php';
        file_put_contents($testFile, '<?php class User {}');
        
        // Set file path for both variations
        $this->cache->setFilePathForClass('App\\Models\\User', $testFile);
        $this->cache->setFilePathForClass('\\App\\Models\\User', $testFile);
        
        // Test with and without leading backslash
        $this->cache->setPropertyType('\\App\\Models\\User', 'email', 'string');
        
        // Should retrieve regardless of leading backslash
        $type1 = $this->cache->getPropertyType('App\\Models\\User', 'email');
        $type2 = $this->cache->getPropertyType('\\App\\Models\\User', 'email');
        
        $this->assertNotNull($type1);
        $this->assertNotNull($type2);
        $this->assertEquals($type1, $type2);
    }

    public function testFileTimestampValidation(): void
    {
        // Create a temporary file
        $testFile = $this->tempDir . '/TestClass.php';
        file_put_contents($testFile, '<?php class TestClass {}');
        
        $this->cache->setFilePathForClass('TestClass', $testFile);
        $this->cache->setPropertyType('TestClass', 'data', 'array');
        
        // Type should be retrievable
        $type = $this->cache->getPropertyType('TestClass', 'data');
        $this->assertNotNull($type);
        
        // Touch the file to update its timestamp
        sleep(1); // Ensure timestamp difference
        touch($testFile);
        
        // Type should now be invalid due to file modification
        $type = $this->cache->getPropertyType('TestClass', 'data');
        $this->assertNull($type);
    }

    public function testComplexTypes(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/ComplexData.php';
        file_put_contents($testFile, '<?php class ComplexData {}');
        
        $this->cache->setFilePathForClass('App\\Data\\ComplexData', $testFile);
        
        $complexType = 'array<string, array{id: int, name: string, tags: array<int, string>}>';
        $this->cache->setPropertyType('App\\Data\\ComplexData', 'items', $complexType, 'array');
        
        $type = $this->cache->getPropertyType('App\\Data\\ComplexData', 'items');
        $this->assertNotNull($type);
        $this->assertEquals($complexType, $type['phpDoc']);
    }

    public function testMethodWithoutParameters(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/Helper.php';
        file_put_contents($testFile, '<?php class Helper {}');
        
        $this->cache->setFilePathForClass('App\\Utils\\Helper', $testFile);
        
        $this->cache->setMethodTypes(
            'App\\Utils\\Helper',
            'getInstance',
            [],
            'self',
            'static'
        );
        
        $returnType = $this->cache->getMethodReturnType('App\\Utils\\Helper', 'getInstance');
        $this->assertNotNull($returnType);
        $this->assertEquals('self', $returnType['native']);
        $this->assertEquals('static', $returnType['phpDoc']);
    }

    public function testNullableTypes(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/User.php';
        file_put_contents($testFile, '<?php class User {}');
        
        $this->cache->setFilePathForClass('App\\Models\\User', $testFile);
        
        $this->cache->setPropertyType('App\\Models\\User', 'deletedAt', '?\\DateTimeInterface', '?\\DateTimeInterface');
        
        $type = $this->cache->getPropertyType('App\\Models\\User', 'deletedAt');
        $this->assertNotNull($type);
        $this->assertEquals('?\\DateTimeInterface', $type['phpDoc']);
    }

    public function testUnionTypes(): void
    {
        // Create a test file
        $testFile = $this->tempDir . '/Response.php';
        file_put_contents($testFile, '<?php class Response {}');
        
        $this->cache->setFilePathForClass('App\\Models\\Response', $testFile);
        
        $this->cache->setPropertyType('App\\Models\\Response', 'data', 'string|array|null', 'string|array|null');
        
        $type = $this->cache->getPropertyType('App\\Models\\Response', 'data');
        $this->assertNotNull($type);
        $this->assertEquals('string|array|null', $type['phpDoc']);
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