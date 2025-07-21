<?php

declare(strict_types=1);

namespace PHPStanFixer\Cache;

use PHPStanFixer\Security\SecureFileOperations;

/**
 * Cache for storing data flow relationships between classes, methods, and properties
 * This captures the "how data flows" not just "what types are"
 */
class FlowCache
{
    private const CACHE_FILE = '.phpstan-fixer-flow-cache.json';
    private const MAX_FLOW_ENTRIES = 25000;
    private const CLEANUP_THRESHOLD = 0.8;
    private const EVICTION_PERCENTAGE = 0.2;
    private const LOCK_TIMEOUT = 10; // seconds
    
    private array $flows = [];
    private string $cacheFile;
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

    /**
     * Record that a parameter flows into a property
     * e.g., processItems($items) -> $this->model->items = $items
     */
    public function recordParameterToPropertyFlow(
        string $fromClass,
        string $fromMethod, 
        string $paramName,
        string $toClass,
        string $toProperty
    ): void {
        // Check cache size before adding new flows
        $this->enforceCacheLimits();
        
        // Normalize class names (remove leading backslashes)
        $fromClass = ltrim($fromClass, '\\');
        $toClass = ltrim($toClass, '\\');
        
        $flowKey = "{$fromClass}::{$fromMethod}::\${$paramName}";
        $targetKey = "{$toClass}::\${$toProperty}";
        
        $this->flows['param_to_property'][$flowKey][] = [
            'target' => $targetKey,
            'timestamp' => time()
        ];
        
        // Update access time for LRU
        $this->accessTimes['param_to_property'][$flowKey] = time();
    }

    /**
     * Record that a property flows into a return value
     * e.g., return $this->model->data; -> getData(): array
     */
    public function recordPropertyToReturnFlow(
        string $fromClass,
        string $fromProperty,
        string $toClass, 
        string $toMethod
    ): void {
        // Check cache size before adding new flows
        $this->enforceCacheLimits();
        
        // Normalize class names (remove leading backslashes)
        $fromClass = ltrim($fromClass, '\\');
        $toClass = ltrim($toClass, '\\');
        
        $sourceKey = "{$fromClass}::\${$fromProperty}";
        $targetKey = "{$toClass}::{$toMethod}::return";
        
        $this->flows['property_to_return'][$sourceKey][] = [
            'target' => $targetKey,
            'timestamp' => time()
        ];
        
        // Update access time for LRU
        $this->accessTimes['property_to_return'][$sourceKey] = time();
    }

    /**
     * Get all properties that a parameter flows into
     */
    public function getParameterFlowTargets(string $className, string $methodName, string $paramName): array
    {
        $className = ltrim($className, '\\');
        $flowKey = "{$className}::{$methodName}::\${$paramName}";
        
        // Update access time for LRU
        if (isset($this->flows['param_to_property'][$flowKey])) {
            $this->accessTimes['param_to_property'][$flowKey] = time();
        }
        
        return $this->flows['param_to_property'][$flowKey] ?? [];
    }

    /**
     * Get all returns that a property flows into  
     */
    public function getPropertyFlowTargets(string $className, string $propertyName): array
    {
        $className = ltrim($className, '\\');
        $sourceKey = "{$className}::\${$propertyName}";
        
        // Update access time for LRU
        if (isset($this->flows['property_to_return'][$sourceKey])) {
            $this->accessTimes['property_to_return'][$sourceKey] = time();
        }
        
        return $this->flows['property_to_return'][$sourceKey] ?? [];
    }

    /**
     * Infer parameter type based on flow analysis
     */
    public function inferParameterTypeFromFlow(string $className, string $methodName, string $paramName, TypeCache $typeCache): ?string
    {
        $targets = $this->getParameterFlowTargets($className, $methodName, $paramName);
        
        foreach ($targets as $target) {
            $targetKey = $target['target'];
            
            // Parse target: "ClassName::$propertyName"
            if (preg_match('/^(.+)::\$(.+)$/', $targetKey, $matches)) {
                $targetClass = ltrim($matches[1], '\\'); // Normalize class name
                $targetProperty = $matches[2];
                
                // Get the type of the target property
                $propertyType = $typeCache->getPropertyType($targetClass, $targetProperty);
                if ($propertyType) {
                    return $propertyType['phpDoc'] ?? $propertyType['native'] ?? null;
                }
            }
        }
        
        return null;
    }

    public function save(): void
    {
        $jsonData = json_encode($this->flows, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new \RuntimeException('Failed to encode flow cache data as JSON');
        }

        // Acquire lock before writing if locking is enabled
        $lockHandle = null;
        if ($this->lockingEnabled && $this->lockManager) {
            $lockHandle = $this->lockManager->acquireLock($this->cacheFile, self::LOCK_TIMEOUT);
            if ($lockHandle === false) {
                throw new \RuntimeException('Failed to acquire lock for flow cache file: ' . $this->cacheFile);
            }
        }
        
        try {
            // Write atomically using a temporary file
            $tempFile = $this->cacheFile . '.tmp.' . uniqid();
            SecureFileOperations::writeFile($tempFile, $jsonData);
            
            // Atomically move temp file to final location
            if (!rename($tempFile, $this->cacheFile)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to save flow cache file atomically');
            }
        } finally {
            // Release lock
            if ($lockHandle !== null && $this->lockManager) {
                $this->lockManager->releaseLock($this->cacheFile, $lockHandle);
            }
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
                // Invalid JSON, start with empty flows
                $this->flows = [];
                return;
            }

            $this->flows = is_array($data) ? $data : [];
            
            // Initialize access times for existing flows
            $currentTime = time();
            foreach ($this->flows as $flowType => $flows) {
                foreach (array_keys($flows) as $key) {
                    $this->accessTimes[$flowType][$key] = $currentTime;
                }
            }
        } catch (\Exception $e) {
            // If cache loading fails, start with empty flows
            $this->flows = [];
        } finally {
            // Release lock
            if ($lockHandle !== null && $this->lockManager) {
                $this->lockManager->releaseLock($this->cacheFile . '.read', $lockHandle);
            }
        }
    }

    public function clear(): void
    {
        $this->flows = [];
        $this->accessTimes = [];
        if (file_exists($this->cacheFile)) {
            SecureFileOperations::deleteFile($this->cacheFile);
        }
    }

    /**
     * Get all flows for a specific type (for advanced inference)
     */
    public function getFlows(string $type): array
    {
        // Update access times for all flows of this type
        if (isset($this->flows[$type])) {
            $currentTime = time();
            foreach (array_keys($this->flows[$type]) as $key) {
                $this->accessTimes[$type][$key] = $currentTime;
            }
        }
        
        return $this->flows[$type] ?? [];
    }
    
    /**
     * Enforce cache size limits and clean up if necessary
     */
    private function enforceCacheLimits(): void
    {
        $totalFlows = $this->getTotalFlowCount();
        
        if ($totalFlows >= self::MAX_FLOW_ENTRIES * self::CLEANUP_THRESHOLD) {
            $this->evictLeastRecentlyUsedFlows();
        }
    }
    
    /**
     * Get total count of all flow entries
     */
    private function getTotalFlowCount(): int
    {
        $total = 0;
        foreach ($this->flows as $flowType => $flows) {
            foreach ($flows as $key => $targets) {
                $total += count($targets);
            }
        }
        return $total;
    }
    
    /**
     * Evict least recently used flow entries
     */
    private function evictLeastRecentlyUsedFlows(): void
    {
        if (empty($this->accessTimes)) {
            $this->evictOldestFlows();
            return;
        }
        
        // Collect all flows with their access times
        $allFlows = [];
        foreach ($this->accessTimes as $flowType => $flows) {
            foreach ($flows as $key => $accessTime) {
                $allFlows[] = [
                    'type' => $flowType,
                    'key' => $key,
                    'accessTime' => $accessTime
                ];
            }
        }
        
        // Sort by access time (oldest first)
        usort($allFlows, function($a, $b) {
            return $a['accessTime'] <=> $b['accessTime'];
        });
        
        // Remove oldest flows
        $toRemove = (int)(count($allFlows) * self::EVICTION_PERCENTAGE);
        
        for ($i = 0; $i < $toRemove && $i < count($allFlows); $i++) {
            $flow = $allFlows[$i];
            $flowType = $flow['type'];
            $key = $flow['key'];
            
            // Remove the flow entry
            unset($this->flows[$flowType][$key]);
            unset($this->accessTimes[$flowType][$key]);
            
            // Clean up empty flow type arrays
            if (empty($this->flows[$flowType])) {
                unset($this->flows[$flowType]);
            }
            if (empty($this->accessTimes[$flowType])) {
                unset($this->accessTimes[$flowType]);
            }
        }
    }
    
    /**
     * Evict oldest flows when no access time tracking available
     */
    private function evictOldestFlows(): void
    {
        // Collect all flows with their timestamps
        $allFlows = [];
        foreach ($this->flows as $flowType => $flows) {
            foreach ($flows as $key => $targets) {
                foreach ($targets as $index => $target) {
                    $allFlows[] = [
                        'type' => $flowType,
                        'key' => $key,
                        'index' => $index,
                        'timestamp' => $target['timestamp'] ?? 0
                    ];
                }
            }
        }
        
        // Sort by timestamp (oldest first)
        usort($allFlows, function($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });
        
        // Remove oldest flow entries
        $toRemove = (int)(count($allFlows) * self::EVICTION_PERCENTAGE);
        
        for ($i = 0; $i < $toRemove && $i < count($allFlows); $i++) {
            $flow = $allFlows[$i];
            $flowType = $flow['type'];
            $key = $flow['key'];
            $index = $flow['index'];
            
            // Remove the specific flow target
            if (isset($this->flows[$flowType][$key][$index])) {
                unset($this->flows[$flowType][$key][$index]);
                
                // Re-index the array
                $this->flows[$flowType][$key] = array_values($this->flows[$flowType][$key]);
                
                // Remove empty arrays
                if (empty($this->flows[$flowType][$key])) {
                    unset($this->flows[$flowType][$key]);
                }
                if (empty($this->flows[$flowType])) {
                    unset($this->flows[$flowType]);
                }
            }
        }
    }
    
    /**
     * Get cache statistics for monitoring
     */
    public function getCacheStats(): array
    {
        $flowCounts = [];
        $totalFlows = 0;
        
        foreach ($this->flows as $flowType => $flows) {
            $count = 0;
            foreach ($flows as $targets) {
                $count += count($targets);
            }
            $flowCounts[$flowType] = $count;
            $totalFlows += $count;
        }
        
        return [
            'totalFlows' => $totalFlows,
            'maxFlows' => self::MAX_FLOW_ENTRIES,
            'usagePercentage' => round(($totalFlows / self::MAX_FLOW_ENTRIES) * 100, 2),
            'flowTypeBreakdown' => $flowCounts,
            'accessTimesTracked' => array_sum(array_map('count', $this->accessTimes)),
            'memoryUsageBytes' => memory_get_usage(),
        ];
    }
    
    /**
     * Clean up old flow entries based on timestamp
     */
    public function cleanupOldFlows(int $maxAge = 86400): int // Default: 24 hours
    {
        $removedCount = 0;
        $cutoffTime = time() - $maxAge;
        
        foreach ($this->flows as $flowType => &$flows) {
            foreach ($flows as $key => &$targets) {
                $targets = array_filter($targets, function($target) use ($cutoffTime) {
                    return ($target['timestamp'] ?? 0) > $cutoffTime;
                });
                
                $originalCount = count($targets);
                $targets = array_values($targets); // Re-index
                $removedCount += $originalCount - count($targets);
                
                // Remove empty flow keys
                if (empty($targets)) {
                    unset($flows[$key]);
                    unset($this->accessTimes[$flowType][$key]);
                }
            }
            
            // Remove empty flow types
            if (empty($flows)) {
                unset($this->flows[$flowType]);
                unset($this->accessTimes[$flowType]);
            }
        }
        
        return $removedCount;
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
    
    /**
     * Perform maintenance tasks
     */
    public function performMaintenance(): array
    {
        $results = [
            'old_flows_removed' => $this->cleanupOldFlows(),
            'locks_cleaned' => 0,
        ];
        
        if ($this->lockManager) {
            $results['locks_cleaned'] = $this->lockManager->cleanupAllLocks();
        }
        
        return $results;
    }
} 