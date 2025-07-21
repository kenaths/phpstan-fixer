<?php

declare(strict_types=1);

namespace PHPStanFixer\Cache;

use PHPStanFixer\Security\SecureFileOperations;

/**
 * Manages file locks for concurrent access safety
 * Implements advisory locking with timeout and retry mechanisms
 */
class FileLockManager
{
    private const LOCK_EXTENSION = '.lock';
    private const DEFAULT_TIMEOUT = 30; // seconds
    private const RETRY_DELAY = 100000; // microseconds (0.1 second)
    private const MAX_RETRIES = 50; // 5 seconds total retry time
    
    private array $activeLocks = [];
    private string $lockDir;
    
    public function __construct(string $projectRoot)
    {
        $this->lockDir = $projectRoot . '/.phpstan-fixer-locks';
        $this->ensureLockDirectory();
    }
    
    /**
     * Acquire an exclusive lock for a file
     * 
     * @param string $filePath The file to lock
     * @param int $timeout Maximum time to wait for lock (seconds)
     * @return resource|false Lock resource on success, false on failure
     */
    public function acquireLock(string $filePath, int $timeout = self::DEFAULT_TIMEOUT)
    {
        $lockFile = $this->getLockFilePath($filePath);
        $startTime = time();
        $retries = 0;
        
        while ($retries < self::MAX_RETRIES && (time() - $startTime) < $timeout) {
            // Try to create lock file atomically
            $lockHandle = @fopen($lockFile, 'x');
            
            if ($lockHandle !== false) {
                // Successfully created lock file
                if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                    // Write process info to lock file
                    fwrite($lockHandle, json_encode([
                        'pid' => getmypid(),
                        'time' => time(),
                        'file' => $filePath,
                    ]));
                    fflush($lockHandle);
                    
                    $this->activeLocks[$filePath] = $lockHandle;
                    return $lockHandle;
                } else {
                    // Failed to get exclusive lock
                    fclose($lockHandle);
                    @unlink($lockFile);
                }
            } else {
                // Lock file already exists, check if it's stale
                if (file_exists($lockFile)) {
                    $this->cleanupStaleLock($lockFile);
                }
            }
            
            // Wait before retrying
            usleep(self::RETRY_DELAY);
            $retries++;
        }
        
        return false;
    }
    
    /**
     * Release a lock
     * 
     * @param string $filePath The file to unlock
     * @param resource|null $lockHandle The lock handle (optional)
     */
    public function releaseLock(string $filePath, $lockHandle = null): void
    {
        if ($lockHandle === null) {
            $lockHandle = $this->activeLocks[$filePath] ?? null;
        }
        
        if ($lockHandle !== null && is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
        
        $lockFile = $this->getLockFilePath($filePath);
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
        
        unset($this->activeLocks[$filePath]);
    }
    
    /**
     * Check if a file is currently locked
     */
    public function isLocked(string $filePath): bool
    {
        $lockFile = $this->getLockFilePath($filePath);
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        // Try to get shared lock to check if file is actively locked
        $handle = @fopen($lockFile, 'r');
        if ($handle === false) {
            return false;
        }
        
        $canLock = flock($handle, LOCK_SH | LOCK_NB);
        if ($canLock) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        
        return !$canLock;
    }
    
    /**
     * Get lock file path for a given file
     */
    private function getLockFilePath(string $filePath): string
    {
        // Create a safe filename for the lock
        $safeName = md5($filePath) . '_' . basename($filePath);
        return $this->lockDir . '/' . $safeName . self::LOCK_EXTENSION;
    }
    
    /**
     * Clean up stale lock files
     */
    private function cleanupStaleLock(string $lockFile): void
    {
        if (!file_exists($lockFile)) {
            return;
        }
        
        $content = @file_get_contents($lockFile);
        if ($content === false) {
            return;
        }
        
        $lockInfo = json_decode($content, true);
        if (!is_array($lockInfo) || !isset($lockInfo['pid']) || !isset($lockInfo['time'])) {
            // Invalid lock file, remove it
            @unlink($lockFile);
            return;
        }
        
        // Check if process is still running
        $pid = (int)$lockInfo['pid'];
        $lockTime = (int)$lockInfo['time'];
        
        // If lock is older than timeout, consider it stale
        if (time() - $lockTime > self::DEFAULT_TIMEOUT * 2) {
            @unlink($lockFile);
            return;
        }
        
        // Check if process exists (Unix-like systems)
        if (function_exists('posix_kill')) {
            if (!posix_kill($pid, 0)) {
                // Process doesn't exist, remove stale lock
                @unlink($lockFile);
            }
        }
    }
    
    /**
     * Ensure lock directory exists and is secure
     */
    private function ensureLockDirectory(): void
    {
        if (!is_dir($this->lockDir)) {
            if (!mkdir($this->lockDir, 0750, true)) {
                throw new \RuntimeException("Failed to create lock directory: {$this->lockDir}");
            }
        }
        
        // Validate directory security
        SecureFileOperations::validateCacheDirectory(dirname($this->lockDir));
    }
    
    /**
     * Clean up all lock files (for maintenance)
     */
    public function cleanupAllLocks(): int
    {
        if (!is_dir($this->lockDir)) {
            return 0;
        }
        
        $removed = 0;
        $files = glob($this->lockDir . '/*' . self::LOCK_EXTENSION);
        
        foreach ($files as $file) {
            $this->cleanupStaleLock($file);
            if (!file_exists($file)) {
                $removed++;
            }
        }
        
        return $removed;
    }
    
    /**
     * Release all active locks (cleanup)
     */
    public function releaseAllLocks(): void
    {
        foreach ($this->activeLocks as $filePath => $lockHandle) {
            $this->releaseLock($filePath, $lockHandle);
        }
        $this->activeLocks = [];
    }
    
    /**
     * Destructor to ensure cleanup
     */
    public function __destruct()
    {
        $this->releaseAllLocks();
    }
}