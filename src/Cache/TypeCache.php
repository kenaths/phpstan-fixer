<?php

declare(strict_types=1);

namespace PHPStanFixer\Cache;

use PHPStanFixer\Security\SecureFileOperations;

class TypeCache
{
    private const CACHE_FILE = '.phpstan-fixer-cache.json';
    private const MAX_CACHE_ENTRIES = 50000;
    private const CLEANUP_THRESHOLD = 0.8;
    private const EVICTION_PERCENTAGE = 0.2;
    private const LOCK_TIMEOUT = 10; // seconds
    
    private array $cache = [];
    private string $cacheFile;
    private array $fileTimestamps = [];
    private array $accessTimes = []; // For LRU tracking
    private ?FileLockManager $lockManager = null;
    private bool $lockingEnabled = true;

    public function __construct(string $projectRoot, bool $enableLocking = true)
    {
        // Validate and secure the project root directory
        SecureFileOperations::validateCacheDirectory($projectRoot);
        
        $this->cacheFile = $projectRoot . '/' . self::CACHE_FILE;
        $this->lockingEnabled = $enableLocking;
        
        // Validate the cache file path
        SecureFileOperations::validatePath($this->cacheFile);
        
        // Initialize lock manager if locking is enabled
        if ($this->lockingEnabled) {
            $this->lockManager = new FileLockManager($projectRoot);
        }
        
        $this->loadCache();
    }

    public function setType(string $className, string $element, array $typeInfo): void
    {
        $key = $this->generateKey($className, $element);
        
        // Check if cache is approaching size limit
        if (count($this->cache) >= self::MAX_CACHE_ENTRIES * self::CLEANUP_THRESHOLD) {
            $this->evictLeastRecentlyUsed();
        }
        
        $this->cache[$key] = [
            'type' => $typeInfo,
            'timestamp' => time(),
            'file' => $this->getFilePathForClass($className),
        ];
        $this->accessTimes[$key] = time();
    }

    public function getType(string $className, string $element): ?array
    {
        $key = $this->generateKey($className, $element);
        
        if (!isset($this->cache[$key])) {
            return null;
        }

        $cacheEntry = $this->cache[$key];
        
        // Check if the cache entry is still valid
        if ($this->isCacheEntryValid($cacheEntry)) {
            // Update access time for LRU
            $this->accessTimes[$key] = time();
            return $cacheEntry['type'];
        }

        // Remove stale entry
        unset($this->cache[$key]);
        unset($this->accessTimes[$key]);
        return null;
    }

    public function save(): void
    {
        $data = [
            'version' => '1.0',
            'cache' => $this->cache,
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new \RuntimeException('Failed to encode cache data as JSON');
        }

        // Acquire lock before writing if locking is enabled
        $lockHandle = null;
        if ($this->lockingEnabled && $this->lockManager) {
            $lockHandle = $this->lockManager->acquireLock($this->cacheFile, self::LOCK_TIMEOUT);
            if ($lockHandle === false) {
                throw new \RuntimeException('Failed to acquire lock for cache file: ' . $this->cacheFile);
            }
        }
        
        try {
            // Write atomically using a temporary file
            $tempFile = $this->cacheFile . '.tmp.' . uniqid();
            SecureFileOperations::writeFile($tempFile, $jsonData);
            
            // Atomically move temp file to final location
            if (!rename($tempFile, $this->cacheFile)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to save cache file atomically');
            }
        } finally {
            // Release lock
            if ($lockHandle !== null && $this->lockManager) {
                $this->lockManager->releaseLock($this->cacheFile, $lockHandle);
            }
        }
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->accessTimes = [];
        if (file_exists($this->cacheFile)) {
            SecureFileOperations::deleteFile($this->cacheFile);
        }
    }

    private function loadCache(): void
    {
        if (!file_exists($this->cacheFile)) {
            return;
        }

        // Acquire shared lock for reading if locking is enabled
        $lockHandle = null;
        if ($this->lockingEnabled && $this->lockManager) {
            $lockHandle = $this->lockManager->acquireLock($this->cacheFile . '.read', self::LOCK_TIMEOUT);
            // If we can't get a lock, proceed anyway (best effort)
        }

        try {
            $content = SecureFileOperations::readFile($this->cacheFile);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Invalid JSON, clear cache and continue
                $this->cache = [];
                return;
            }

            if ($data && isset($data['cache']) && is_array($data['cache'])) {
                $this->cache = $data['cache'];
                
                // Initialize access times for existing entries
                $currentTime = time();
                foreach (array_keys($this->cache) as $key) {
                    $this->accessTimes[$key] = $currentTime;
                }
            }
        } catch (\Exception $e) {
            // If cache loading fails, start with empty cache
            $this->cache = [];
        } finally {
            // Release lock
            if ($lockHandle !== null && $this->lockManager) {
                $this->lockManager->releaseLock($this->cacheFile . '.read', $lockHandle);
            }
        }
    }

    private function generateKey(string $className, string $element): string
    {
        // Normalize class name
        $className = ltrim($className, '\\');
        
        // Handle different element types
        if (strpos($element, '$') === 0) {
            // Property
            return $className . '::' . $element;
        } elseif (strpos($element, '(') !== false) {
            // Method with parameters
            $methodName = substr($element, 0, strpos($element, '('));
            return $className . '::' . $methodName . '()';
        } else {
            // Method without parameters or other elements
            return $className . '::' . $element;
        }
    }

    private function isCacheEntryValid(array $cacheEntry): bool
    {
        if (!isset($cacheEntry['file']) || !file_exists($cacheEntry['file'])) {
            return false;
        }

        $currentTimestamp = filemtime($cacheEntry['file']);
        $cachedTimestamp = $cacheEntry['timestamp'] ?? 0;

        return $currentTimestamp <= $cachedTimestamp;
    }

    private function getFilePathForClass(string $className): string
    {
        // This is a simplified implementation
        // In a real scenario, we'd need to use the autoloader or parse composer.json
        // For now, we'll store this information when types are discovered
        $className = ltrim($className, '\\');
        return $this->fileTimestamps[$className] ?? '';
    }

    public function setFilePathForClass(string $className, string $filePath): void
    {
        $this->fileTimestamps[ltrim($className, '\\')] = $filePath;
    }

    public function getPropertyType(string $className, string $propertyName): ?array
    {
        return $this->getType($className, '$' . ltrim($propertyName, '$'));
    }

    public function getMethodReturnType(string $className, string $methodName): ?array
    {
        $typeInfo = $this->getType($className, $methodName . '()');
        return $typeInfo['return'] ?? null;
    }

    public function getMethodParameterTypes(string $className, string $methodName): ?array
    {
        $typeInfo = $this->getType($className, $methodName . '()');
        return $typeInfo['params'] ?? null;
    }

    public function setPropertyType(string $className, string $propertyName, string $phpDocType, ?string $nativeType = null): void
    {
        $this->setType($className, '$' . ltrim($propertyName, '$'), [
            'phpDoc' => $phpDocType,
            'native' => $nativeType,
        ]);
    }
    
    /**
     * Enable or disable locking for this cache instance
     */
    public function setLockingEnabled(bool $enabled): void
    {
        $this->lockingEnabled = $enabled;
    }
    
    /**
     * Get lock manager instance
     */
    public function getLockManager(): ?FileLockManager
    {
        return $this->lockManager;
    }

    public function setMethodTypes(string $className, string $methodName, array $paramTypes, ?string $returnType, ?string $phpDocReturn = null): void
    {
        $this->setType($className, $methodName . '()', [
            'params' => $paramTypes,
            'return' => [
                'native' => $returnType,
                'phpDoc' => $phpDocReturn,
            ],
        ]);
    }
    
    /**
     * Evict least recently used cache entries to manage memory
     */
    private function evictLeastRecentlyUsed(): void
    {
        if (empty($this->cache)) {
            return;
        }
        
        // If no access times, remove oldest entries by timestamp
        if (empty($this->accessTimes)) {
            $this->evictOldestEntries();
            return;
        }
        
        // Sort access times (oldest first)
        asort($this->accessTimes);
        
        // Calculate how many entries to remove
        $toRemove = (int)(count($this->cache) * self::EVICTION_PERCENTAGE);
        $removed = 0;
        
        foreach ($this->accessTimes as $key => $accessTime) {
            if ($removed >= $toRemove) {
                break;
            }
            
            unset($this->cache[$key]);
            unset($this->accessTimes[$key]);
            $removed++;
        }
    }
    
    /**
     * Evict oldest entries when no access time tracking available
     */
    private function evictOldestEntries(): void
    {
        // Sort by timestamp (oldest first)
        uasort($this->cache, function($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });
        
        $toRemove = (int)(count($this->cache) * self::EVICTION_PERCENTAGE);
        $removed = 0;
        
        foreach (array_keys($this->cache) as $key) {
            if ($removed >= $toRemove) {
                break;
            }
            
            unset($this->cache[$key]);
            unset($this->accessTimes[$key]);
            $removed++;
        }
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        return [
            'entries' => count($this->cache),
            'maxEntries' => self::MAX_CACHE_ENTRIES,
            'usagePercentage' => round((count($this->cache) / self::MAX_CACHE_ENTRIES) * 100, 2),
            'accessTimesTracked' => count($this->accessTimes),
            'memoryUsageBytes' => memory_get_usage(),
        ];
    }
    
    /**
     * Force cleanup of stale entries
     */
    public function cleanupStaleEntries(): int
    {
        $removedCount = 0;
        $keysToRemove = [];
        
        foreach ($this->cache as $key => $cacheEntry) {
            if (!$this->isCacheEntryValid($cacheEntry)) {
                $keysToRemove[] = $key;
            }
        }
        
        foreach ($keysToRemove as $key) {
            unset($this->cache[$key]);
            unset($this->accessTimes[$key]);
            $removedCount++;
        }
        
        return $removedCount;
    }
    
    /**
     * Perform maintenance tasks (cleanup locks, stale entries, etc.)
     */
    public function performMaintenance(): array
    {
        $results = [
            'stale_entries_removed' => $this->cleanupStaleEntries(),
            'locks_cleaned' => 0,
        ];
        
        if ($this->lockManager) {
            $results['locks_cleaned'] = $this->lockManager->cleanupAllLocks();
        }
        
        return $results;
    }
}