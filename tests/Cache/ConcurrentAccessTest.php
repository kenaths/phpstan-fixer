<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Cache;

use PHPStanFixer\Cache\TypeCache;
use PHPStanFixer\Cache\FlowCache;
use PHPStanFixer\Cache\FileLockManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for concurrent access safety
 */
class ConcurrentAccessTest extends TestCase
{
    private string $tempDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpstan-fixer-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }
    
    public function testTypeCacheConcurrentWrite(): void
    {
        $cache1 = new TypeCache($this->tempDir, true);
        $cache2 = new TypeCache($this->tempDir, true);
        
        // Both caches try to write different data
        $cache1->setPropertyType('TestClass', 'prop1', 'string');
        $cache2->setPropertyType('TestClass', 'prop2', 'int');
        
        // Save both (second one should wait for lock)
        $cache1->save();
        $cache2->save();
        
        // Load fresh cache and verify both writes succeeded
        $cache3 = new TypeCache($this->tempDir, true);
        
        $prop1Type = $cache3->getPropertyType('TestClass', 'prop1');
        $prop2Type = $cache3->getPropertyType('TestClass', 'prop2');
        
        $this->assertNotNull($prop1Type);
        $this->assertEquals('string', $prop1Type['phpDoc']);
        
        $this->assertNotNull($prop2Type);
        $this->assertEquals('int', $prop2Type['phpDoc']);
    }
    
    public function testFlowCacheConcurrentWrite(): void
    {
        $cache1 = new FlowCache($this->tempDir, true);
        $cache2 = new FlowCache($this->tempDir, true);
        
        // Both caches try to record different flows
        $cache1->recordParameterToPropertyFlow('Class1', 'method1', 'param1', 'Target1', 'prop1');
        $cache2->recordParameterToPropertyFlow('Class2', 'method2', 'param2', 'Target2', 'prop2');
        
        // Save both
        $cache1->save();
        $cache2->save();
        
        // Load fresh cache and verify both writes succeeded
        $cache3 = new FlowCache($this->tempDir, true);
        
        $flow1 = $cache3->getParameterFlowTargets('Class1', 'method1', 'param1');
        $flow2 = $cache3->getParameterFlowTargets('Class2', 'method2', 'param2');
        
        $this->assertNotEmpty($flow1);
        $this->assertStringContainsString('Target1', $flow1[0]['target']);
        
        $this->assertNotEmpty($flow2);
        $this->assertStringContainsString('Target2', $flow2[0]['target']);
    }
    
    public function testLockTimeout(): void
    {
        $lockManager = new FileLockManager($this->tempDir);
        
        // Acquire lock
        $lock1 = $lockManager->acquireLock('test.txt', 1);
        $this->assertNotFalse($lock1);
        
        // Try to acquire same lock (should fail after timeout)
        $startTime = microtime(true);
        $lock2 = $lockManager->acquireLock('test.txt', 1);
        $duration = microtime(true) - $startTime;
        
        $this->assertFalse($lock2);
        $this->assertGreaterThanOrEqual(1.0, $duration);
        $this->assertLessThan(2.0, $duration); // Should timeout around 1 second
        
        // Release first lock
        $lockManager->releaseLock('test.txt', $lock1);
        
        // Now we should be able to acquire it
        $lock3 = $lockManager->acquireLock('test.txt', 1);
        $this->assertNotFalse($lock3);
        $lockManager->releaseLock('test.txt', $lock3);
    }
    
    public function testStaleLockCleanup(): void
    {
        $lockFile = $this->tempDir . '/.phpstan-fixer-locks/test.lock';
        mkdir(dirname($lockFile), 0777, true);
        
        // Create a stale lock file
        file_put_contents($lockFile, json_encode([
            'pid' => 99999999, // Non-existent PID
            'time' => time() - 3600, // 1 hour old
            'file' => 'test.txt'
        ]));
        
        $lockManager = new FileLockManager($this->tempDir);
        
        // Should be able to acquire lock despite stale lock file
        $lock = $lockManager->acquireLock('test.txt', 1);
        $this->assertNotFalse($lock);
        
        $lockManager->releaseLock('test.txt', $lock);
    }
    
    public function testCacheWithoutLocking(): void
    {
        // Test that caches work without locking enabled
        $cache = new TypeCache($this->tempDir, false);
        
        $cache->setPropertyType('TestClass', 'prop', 'string');
        $cache->save();
        
        $type = $cache->getPropertyType('TestClass', 'prop');
        $this->assertNotNull($type);
        $this->assertEquals('string', $type['phpDoc']);
    }
    
    public function testAtomicSaveFailure(): void
    {
        $cache = new TypeCache($this->tempDir, true);
        $cache->setPropertyType('TestClass', 'prop', 'string');
        
        // Make cache directory read-only to simulate write failure
        chmod($this->tempDir, 0555);
        
        try {
            $cache->save();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to', $e->getMessage());
        } finally {
            // Restore permissions
            chmod($this->tempDir, 0777);
        }
    }
    
    public function testMaintenanceTasks(): void
    {
        $cache = new TypeCache($this->tempDir, true);
        
        // Add some data
        $cache->setPropertyType('TestClass1', 'prop1', 'string');
        $cache->setPropertyType('TestClass2', 'prop2', 'int');
        $cache->save();
        
        // Perform maintenance
        $results = $cache->performMaintenance();
        
        $this->assertArrayHasKey('stale_entries_removed', $results);
        $this->assertArrayHasKey('locks_cleaned', $results);
        $this->assertIsInt($results['stale_entries_removed']);
        $this->assertIsInt($results['locks_cleaned']);
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