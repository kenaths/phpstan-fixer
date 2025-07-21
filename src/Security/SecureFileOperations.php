<?php

declare(strict_types=1);

namespace PHPStanFixer\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Secure file operations utility to prevent path traversal and other file-based attacks
 */
class SecureFileOperations
{
    private const MAX_PATH_LENGTH = 4096;
    private const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB
    
    /**
     * Validate a file path for security
     */
    public static function validatePath(string $path): void
    {
        if (!is_string($path) || empty($path)) {
            throw new InvalidArgumentException('Path must be a non-empty string');
        }
        
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new InvalidArgumentException('Path too long (max ' . self::MAX_PATH_LENGTH . ' characters)');
        }
        
        // Prevent null byte injection
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains null byte');
        }
        
        // Prevent obvious path traversal attempts
        if (str_contains($path, '..')) {
            throw new InvalidArgumentException('Path contains directory traversal attempts');
        }
        
        // Validate against other dangerous patterns
        $dangerousPatterns = [
            '/\.\./', // Any form of ..
            '/\/\/+/', // Multiple slashes
            '/^\/proc\//', // Proc filesystem
            '/^\/dev\//', // Device files
            '/^\/sys\//', // System files
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                throw new InvalidArgumentException('Path contains dangerous pattern: ' . $path);
            }
        }
    }

    /**
     * Securely normalize a file path
     */
    public static function normalizePath(string $path): string
    {
        self::validatePath($path);
        
        // Get the real path if it exists
        $realPath = realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }
        
        // If path doesn't exist, normalize manually
        $parts = explode('/', str_replace('\\', '/', $path));
        $normalizedParts = [];
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException('Path contains directory traversal');
            }
            $normalizedParts[] = $part;
        }
        
        $normalized = '/' . implode('/', $normalizedParts);
        
        // Re-validate the normalized path
        self::validatePath($normalized);
        
        return $normalized;
    }

    /**
     * Safely read file contents with size limits
     */
    public static function readFile(string $path): string
    {
        self::validatePath($path);
        
        if (!is_file($path)) {
            throw new InvalidArgumentException("Path is not a file: {$path}");
        }
        
        if (!is_readable($path)) {
            throw new InvalidArgumentException("File is not readable: {$path}");
        }
        
        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new RuntimeException("Could not determine file size: {$path}");
        }
        
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException("File too large (max " . self::MAX_FILE_SIZE . " bytes): {$path}");
        }
        
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }
        
        return $contents;
    }

    /**
     * Safely write file contents with directory creation
     */
    public static function writeFile(string $path, string $contents): void
    {
        self::validatePath($path);
        
        // Check if contents size is reasonable
        if (strlen($contents) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException("Contents too large (max " . self::MAX_FILE_SIZE . " bytes)");
        }
        
        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }
        
        // Write with atomic operation using temp file
        $tempPath = $path . '.tmp.' . uniqid();
        
        try {
            if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Failed to write temp file: {$tempPath}");
            }
            
            // Set secure permissions
            chmod($tempPath, 0644);
            
            // Atomic move
            if (!rename($tempPath, $path)) {
                throw new RuntimeException("Failed to move temp file to final location: {$path}");
            }
        } catch (\Throwable $e) {
            // Clean up temp file on failure
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    /**
     * Safely delete a file
     */
    public static function deleteFile(string $path): void
    {
        self::validatePath($path);
        
        if (!file_exists($path)) {
            return; // Already deleted
        }
        
        if (!is_file($path)) {
            throw new InvalidArgumentException("Path is not a file: {$path}");
        }
        
        if (!unlink($path)) {
            throw new RuntimeException("Failed to delete file: {$path}");
        }
    }

    /**
     * Check if a path is within an allowed directory
     */
    public static function isPathWithinDirectory(string $path, string $allowedDir): bool
    {
        try {
            $normalizedPath = self::normalizePath($path);
            $normalizedAllowedDir = self::normalizePath($allowedDir);
            
            return str_starts_with($normalizedPath, $normalizedAllowedDir . '/') || 
                   $normalizedPath === $normalizedAllowedDir;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Create a secure temporary file
     */
    public static function createTempFile(string $prefix = 'phpstan-fixer-', string $suffix = '.tmp'): string
    {
        $tempDir = sys_get_temp_dir();
        
        // Validate temp directory
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new RuntimeException("Temp directory not accessible: {$tempDir}");
        }
        
        // Create unique temp file
        $attempts = 0;
        do {
            $filename = $prefix . bin2hex(random_bytes(8)) . $suffix;
            $tempPath = $tempDir . DIRECTORY_SEPARATOR . $filename;
            $attempts++;
            
            if ($attempts > 100) {
                throw new RuntimeException('Failed to create unique temp file after 100 attempts');
            }
        } while (file_exists($tempPath));
        
        // Create the file with secure permissions
        $handle = fopen($tempPath, 'x'); // 'x' fails if file exists
        if ($handle === false) {
            throw new RuntimeException("Failed to create temp file: {$tempPath}");
        }
        
        fclose($handle);
        chmod($tempPath, 0600); // Owner read/write only
        
        return $tempPath;
    }

    /**
     * Validate that a directory path is safe for cache operations
     */
    public static function validateCacheDirectory(string $dir): void
    {
        self::validatePath($dir);
        
        // Additional validations for cache directories
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("Cache directory does not exist: {$dir}");
        }
        
        if (!is_writable($dir)) {
            throw new InvalidArgumentException("Cache directory is not writable: {$dir}");
        }
        
        // Ensure it's not a system directory
        $systemDirs = ['/bin', '/boot', '/dev', '/etc', '/lib', '/proc', '/root', '/sbin', '/sys', '/usr'];
        foreach ($systemDirs as $systemDir) {
            if (str_starts_with($dir, $systemDir . '/') || $dir === $systemDir) {
                throw new InvalidArgumentException("Cache directory cannot be in system directory: {$dir}");
            }
        }
    }
}