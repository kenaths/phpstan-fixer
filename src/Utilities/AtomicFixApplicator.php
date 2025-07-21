<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

use PHPStanFixer\Security\SecureFileOperations;
use PHPStanFixer\Safety\SafetyChecker;
use PHPStanFixer\Validation\TypeConsistencyValidator;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Contracts\FixerInterface;

/**
 * Applies fixes atomically with rollback capability
 * Ensures that either all fixes succeed or all changes are rolled back
 */
class AtomicFixApplicator
{
    private array $backups = [];
    private array $appliedFixes = [];
    private string $backupDir;
    private bool $transactionActive = false;
    private SafetyChecker $safetyChecker;
    private TypeConsistencyValidator $typeValidator;
    
    public function __construct(string $projectRoot)
    {
        $this->backupDir = $projectRoot . '/.phpstan-fixer-backup';
        $this->safetyChecker = new SafetyChecker();
        $this->typeValidator = new TypeConsistencyValidator();
        $this->ensureBackupDirectory();
    }
    
    /**
     * Start a transaction for atomic fix application
     */
    public function beginTransaction(): void
    {
        if ($this->transactionActive) {
            throw new \RuntimeException('Transaction already active');
        }
        
        $this->transactionActive = true;
        $this->backups = [];
        $this->appliedFixes = [];
    }
    
    /**
     * Apply a fix atomically within the transaction
     */
    public function applyFix(string $filePath, FixerInterface $fixer, Error $error): bool
    {
        if (!$this->transactionActive) {
            throw new \RuntimeException('No transaction active. Call beginTransaction() first.');
        }
        
        try {
            // Validate file path
            SecureFileOperations::validatePath($filePath);
            
            if (!file_exists($filePath)) {
                throw new \RuntimeException("File does not exist: {$filePath}");
            }
            
            // Create backup before applying fix
            $this->createBackup($filePath);
            
            // Read original content
            $originalContent = SecureFileOperations::readFile($filePath);
            
            // Apply the fix
            $fixedContent = $fixer->fix($originalContent, $error);
            
            // Check if fix actually changed anything
            if ($fixedContent === $originalContent) {
                // No changes made, remove backup
                $this->removeBackup($filePath);
                return false;
            }
            
            // Comprehensive validation of the fixed content
            if (!$this->validateFixedContent($originalContent, $fixedContent)) {
                throw new \RuntimeException('Fixed content failed safety validation');
            }
            
            // Write the fixed content
            SecureFileOperations::writeFile($filePath, $fixedContent);
            
            // Record successful fix
            $this->appliedFixes[] = [
                'file' => $filePath,
                'fixer' => get_class($fixer),
                'error' => $error->getMessage(),
                'line' => $error->getLine(),
                'timestamp' => time(),
            ];
            
            return true;
            
        } catch (\Exception $e) {
            // If fix failed, restore from backup
            if (isset($this->backups[$filePath])) {
                $this->restoreFromBackup($filePath);
            }
            
            throw new \RuntimeException(
                "Fix failed for {$filePath}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Commit all fixes in the transaction
     */
    public function commit(): array
    {
        if (!$this->transactionActive) {
            throw new \RuntimeException('No transaction active');
        }
        
        try {
            // All fixes were successful, clean up backups
            foreach (array_keys($this->backups) as $filePath) {
                $this->removeBackup($filePath);
            }
            
            $appliedFixes = $this->appliedFixes;
            
            // Reset transaction state
            $this->transactionActive = false;
            $this->backups = [];
            $this->appliedFixes = [];
            
            return $appliedFixes;
            
        } catch (\Exception $e) {
            // If commit fails, rollback all changes
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Rollback all fixes in the transaction
     */
    public function rollback(): void
    {
        if (!$this->transactionActive) {
            throw new \RuntimeException('No transaction active');
        }
        
        $errors = [];
        
        // Restore all files from backups
        foreach ($this->backups as $filePath => $backupPath) {
            try {
                $this->restoreFromBackup($filePath);
            } catch (\Exception $e) {
                $errors[] = "Failed to restore {$filePath}: " . $e->getMessage();
            }
        }
        
        // Reset transaction state
        $this->transactionActive = false;
        $this->backups = [];
        $this->appliedFixes = [];
        
        if (!empty($errors)) {
            throw new \RuntimeException('Rollback completed with errors: ' . implode('; ', $errors));
        }
    }
    
    /**
     * Check if a transaction is currently active
     */
    public function isTransactionActive(): bool
    {
        return $this->transactionActive;
    }
    
    /**
     * Get information about applied fixes in the current transaction
     */
    public function getAppliedFixes(): array
    {
        return $this->appliedFixes;
    }
    
    /**
     * Create a backup of the file
     */
    private function createBackup(string $filePath): void
    {
        if (isset($this->backups[$filePath])) {
            // Backup already exists for this file in this transaction
            return;
        }
        
        $backupFileName = 'backup_' . uniqid() . '_' . basename($filePath);
        $backupPath = $this->backupDir . '/' . $backupFileName;
        
        $originalContent = SecureFileOperations::readFile($filePath);
        SecureFileOperations::writeFile($backupPath, $originalContent);
        
        $this->backups[$filePath] = $backupPath;
    }
    
    /**
     * Restore a file from its backup
     */
    private function restoreFromBackup(string $filePath): void
    {
        if (!isset($this->backups[$filePath])) {
            throw new \RuntimeException("No backup found for {$filePath}");
        }
        
        $backupPath = $this->backups[$filePath];
        
        if (!file_exists($backupPath)) {
            throw new \RuntimeException("Backup file missing: {$backupPath}");
        }
        
        $backupContent = SecureFileOperations::readFile($backupPath);
        SecureFileOperations::writeFile($filePath, $backupContent);
    }
    
    /**
     * Remove a backup file
     */
    private function removeBackup(string $filePath): void
    {
        if (!isset($this->backups[$filePath])) {
            return;
        }
        
        $backupPath = $this->backups[$filePath];
        
        if (file_exists($backupPath)) {
            SecureFileOperations::deleteFile($backupPath);
        }
        
        unset($this->backups[$filePath]);
    }
    
    /**
     * Ensure backup directory exists and is secure
     */
    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0750, true)) {
                throw new \RuntimeException("Failed to create backup directory: {$this->backupDir}");
            }
        }
        
        // Secure the backup directory
        SecureFileOperations::validateCacheDirectory(dirname($this->backupDir));
    }
    
    /**
     * Comprehensive validation of fixed content
     */
    private function validateFixedContent(string $originalContent, string $fixedContent): bool
    {
        // 1. Basic syntax check
        if (!$this->validatePhpSyntax($fixedContent)) {
            return false;
        }
        
        // 2. Safety checks (structure preservation, type safety, etc.)
        if (!$this->safetyChecker->isSafeToApply($originalContent, $fixedContent)) {
            return false;
        }
        
        // 3. Type consistency validation (currently disabled for basic type fixes)
        $typeViolations = $this->typeValidator->validateCode($fixedContent);
        if (!empty($typeViolations)) {
            // Log type violations for debugging but don't fail the fix
            error_log('Type validation violations: ' . implode(', ', $typeViolations));
            // Only fail for critical violations that could break code
            $criticalViolations = array_filter($typeViolations, function($violation) {
                return str_contains($violation, 'Syntax error') || 
                       str_contains($violation, 'Fatal error');
            });
            
            if (!empty($criticalViolations)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate PHP syntax of content
     */
    private function validatePhpSyntax(string $content): bool
    {
        // Create a temporary file to check syntax
        $tempFile = SecureFileOperations::createTempFile('syntax_check_', '.php');
        
        try {
            SecureFileOperations::writeFile($tempFile, $content);
            
            // Use php -l to check syntax
            $command = sprintf('php -l %s 2>&1', escapeshellarg($tempFile));
            $output = shell_exec($command);
            
            // Check if syntax is valid
            return $output !== null && str_contains($output, 'No syntax errors detected');
            
        } finally {
            SecureFileOperations::deleteFile($tempFile);
        }
    }
    
    /**
     * Clean up old backup files (housekeeping)
     */
    public function cleanupOldBackups(int $maxAge = 3600): int
    {
        if (!is_dir($this->backupDir)) {
            return 0;
        }
        
        $removed = 0;
        $cutoffTime = time() - $maxAge;
        
        $files = glob($this->backupDir . '/backup_*');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                try {
                    SecureFileOperations::deleteFile($file);
                    $removed++;
                } catch (\Exception $e) {
                    // Log error but continue cleanup
                    error_log("Failed to remove old backup {$file}: " . $e->getMessage());
                }
            }
        }
        
        return $removed;
    }
    
    /**
     * Get backup directory path
     */
    public function getBackupDirectory(): string
    {
        return $this->backupDir;
    }
    
    /**
     * Destructor to ensure cleanup
     */
    public function __destruct()
    {
        if ($this->transactionActive) {
            try {
                $this->rollback();
            } catch (\Exception $e) {
                error_log('Failed to rollback transaction in destructor: ' . $e->getMessage());
            }
        }
    }
}