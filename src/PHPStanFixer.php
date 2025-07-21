<?php

declare(strict_types=1);

namespace PHPStanFixer;

use PHPStanFixer\Cache\TypeCache;
use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\Fixers\CacheAwareFixer;
use PHPStanFixer\Fixers\Registry\FixerRegistry;
use PHPStanFixer\Parser\ErrorParser;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPStanFixer\Utilities\ProjectRootDetector;
use PHPStanFixer\Utilities\AtomicFixApplicator;
use PHPStanFixer\Safety\SafetyChecker;
use PHPStanFixer\Validation\TypeConsistencyValidator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Main PHPStan Fixer class
 */
class PHPStanFixer
{
    private PHPStanRunner $phpstanRunner;
    private ErrorParser $errorParser;
    private FixerRegistry $fixerRegistry;
    private Filesystem $filesystem;
    private ?TypeCache $typeCache = null;
    private ?AtomicFixApplicator $atomicApplicator = null;
    private SafetyChecker $safetyChecker;
    private TypeConsistencyValidator $typeValidator;

    public function __construct(
        ?PHPStanRunner $phpstanRunner = null,
        ?ErrorParser $errorParser = null,
        ?FixerRegistry $fixerRegistry = null
    ) {
        $this->phpstanRunner = $phpstanRunner ?? new PHPStanRunner();
        $this->errorParser = $errorParser ?? new ErrorParser();
        $this->fixerRegistry = $fixerRegistry ?? new FixerRegistry();
        $this->filesystem = new Filesystem();
        $this->safetyChecker = new SafetyChecker();
        $this->typeValidator = new TypeConsistencyValidator();
        
        $this->registerDefaultFixers();
    }

    /**
     * Fix PHPStan errors for the given paths at the specified level
     * 
     * @param array<string> $paths
     * @param array<string, mixed> $options
     * @param bool $createBackup
     * @param bool $atomicMode Use atomic transactions for fix application
     */
    public function fix(array $paths, int $level, array $options = [], bool $createBackup = false, bool $smartMode = false, bool $atomicMode = true): FixResult
    {
        $result = new FixResult();
        
        // Initialize type cache for smart mode and atomic applicator
        $projectRoot = ProjectRootDetector::detectFromPaths($paths);
        
        if ($smartMode) {
            $this->typeCache = new TypeCache($projectRoot);
        }
        
        if ($atomicMode) {
            $this->atomicApplicator = new AtomicFixApplicator($projectRoot);
        }
        
        // Run PHPStan analysis
        $phpstanOutput = $this->phpstanRunner->analyze($paths, $level, $options);
        
        // Parse errors from output
        $errors = $this->errorParser->parse($phpstanOutput);
        
        if (empty($errors)) {
            $result->setMessage('No PHPStan errors found!');
            return $result;
        }
        
        // Group errors by file
        $errorsByFile = $this->groupErrorsByFile($errors);
        
        if ($smartMode) {
            // Multi-pass fixing with type cache
            $pass = 1;
            $maxPasses = 3;
            $totalErrors = count($errors);
            
            while ($pass <= $maxPasses && !empty($errorsByFile)) {
                $result->addMessage("Smart mode: Pass $pass of $maxPasses");
                
                // Fix errors file by file
                foreach ($errorsByFile as $file => $fileErrors) {
                    $this->fixFileErrors($file, $fileErrors, $result, $createBackup, $atomicMode);
                }
                
                // Save cache after each pass
                if ($this->typeCache) {
                    $this->typeCache->save();
                }
                
                // Re-run PHPStan to check remaining errors
                if ($pass < $maxPasses) {
                    $phpstanOutput = $this->phpstanRunner->analyze($paths, $level, $options);
                    $errors = $this->errorParser->parse($phpstanOutput);
                    
                    if (empty($errors)) {
                        $result->addMessage("All errors fixed in pass " . ($pass + 1) . "!");
                        break;
                    }
                    
                    $errorsByFile = $this->groupErrorsByFile($errors);
                    $newTotalErrors = count($errors);
                    
                    // Stop if we're not making progress
                    if ($newTotalErrors >= $totalErrors) {
                        $result->addMessage("No further improvements possible after pass $pass");
                        break;
                    }
                    
                    $totalErrors = $newTotalErrors;
                }
                
                $pass++;
            }
        } else {
            // Single-pass fixing (normal mode)
            foreach ($errorsByFile as $file => $fileErrors) {
                $this->fixFileErrors($file, $fileErrors, $result, $createBackup, $atomicMode);
            }
        }
        
        return $result;
    }

    /**
     * Register default fixers
     */
    private function registerDefaultFixers(): void
    {
        // Register all default fixers
        $this->fixerRegistry->register(new Fixers\MissingReturnTypeFixer());
        $this->fixerRegistry->register(new Fixers\MissingParameterTypeFixer());
        $this->fixerRegistry->register(new Fixers\UndefinedVariableFixer());
        $this->fixerRegistry->register(new Fixers\UnusedVariableFixer());
        $this->fixerRegistry->register(new Fixers\StrictComparisonFixer());
        $this->fixerRegistry->register(new Fixers\NullCoalescingFixer());
        $this->fixerRegistry->register(new Fixers\MissingPropertyTypeFixer());
        $this->fixerRegistry->register(new Fixers\DocBlockFixer());
        
        // PHP 8+ specific fixers
        $this->fixerRegistry->register(new Fixers\UnionTypeFixer());
        $this->fixerRegistry->register(new Fixers\ReadonlyPropertyFixer());
        $this->fixerRegistry->register(new Fixers\EnumFixer());
        $this->fixerRegistry->register(new Fixers\ConstructorPromotionFixer());
        $this->fixerRegistry->register(new Fixers\MissingIterableValueTypeFixer());
        $this->fixerRegistry->register(new Fixers\PropertyHookFixer());
        $this->fixerRegistry->register(new Fixers\AsymmetricVisibilityFixer());
        $this->fixerRegistry->register(new Fixers\GenericTypeFixer($this->phpstanRunner));
        $this->fixerRegistry->register(new Fixers\TypeConsistencyFixer());
        $this->fixerRegistry->register(new Fixers\MissingGenericParameterFixer($this->phpstanRunner));
    }

    /**
     * Register a custom fixer
     */
    public function registerFixer(FixerInterface $fixer): void
    {
        $this->fixerRegistry->register($fixer);
    }

    /**
     * Group errors by file
     * 
     * @param array<\PHPStanFixer\ValueObjects\Error> $errors
     * @return array<string, array<\PHPStanFixer\ValueObjects\Error>>
     */
    private function groupErrorsByFile(array $errors): array
    {
        $grouped = [];
        
        foreach ($errors as $error) {
            $file = $error->getFile();
            if (!isset($grouped[$file])) {
                $grouped[$file] = [];
            }
            $grouped[$file][] = $error;
        }
        
        return $grouped;
    }

    /**
     * Fix errors in a single file
     * 
     * @param array<\PHPStanFixer\ValueObjects\Error> $errors
     */
    private function fixFileErrors(string $file, array $errors, FixResult $result, bool $createBackup, bool $atomicMode = true): void
    {
        // Skip errors without valid file paths
        if ($file === 'unknown') {
            foreach ($errors as $error) {
                $result->addError("PHPStan error without file association: {$error->message}");
            }
            return;
        }
        
        if (!$this->filesystem->exists($file)) {
            $result->addError("File not found: $file");
            return;
        }
        
        if ($atomicMode && $this->atomicApplicator) {
            $this->fixFileErrorsAtomic($file, $errors, $result, $createBackup);
        } else {
            $this->fixFileErrorsLegacy($file, $errors, $result, $createBackup);
        }
    }
    
    /**
     * Fix errors atomically with transaction support
     * 
     * @param array<\PHPStanFixer\ValueObjects\Error> $errors
     */
    private function fixFileErrorsAtomic(string $file, array $errors, FixResult $result, bool $createBackup): void
    {
        try {
            $this->atomicApplicator->beginTransaction();
            $appliedFixes = [];
            
            foreach ($errors as $error) {
                $fixer = $this->fixerRegistry->getFixerForError($error);
                
                if ($fixer === null) {
                    $result->addUnfixableError($error);
                    continue;
                }
                
                try {
                    // Set type cache and current file for cache-aware fixers
                    if ($fixer instanceof CacheAwareFixer && $this->typeCache) {
                        $fixer->setTypeCache($this->typeCache);
                        $fixer->setCurrentFile($file);
                    }
                    
                    // Apply fix atomically
                    $wasFixed = $this->atomicApplicator->applyFix($file, $fixer, $error);
                    
                    if ($wasFixed) {
                        $appliedFixes[] = $error;
                        $result->addFixedError($error);
                    }
                    
                } catch (\Exception $e) {
                    $result->addError("Failed to fix error in $file: " . $e->getMessage());
                    $result->addUnfixableError($error);
                    // Continue with other fixes - atomic applicator will handle rollback if needed
                }
            }
            
            // Commit all fixes if any were applied
            if (!empty($appliedFixes)) {
                $commitedFixes = $this->atomicApplicator->commit();
                
                // Create legacy backup if requested (for compatibility)
                if ($createBackup && !empty($commitedFixes)) {
                    $backupFile = $file . '.phpstan-fixer.bak';
                    // In atomic mode, individual backups are handled by AtomicFixApplicator
                    // For legacy compatibility, create a simple backup
                    if (file_exists($file)) {
                        copy($file, $backupFile);
                        $result->addFixedFile($file, $backupFile);
                    }
                } else if (!empty($commitedFixes)) {
                    $result->addFixedFile($file, null);
                }
            } else {
                // No fixes applied, rollback transaction
                $this->atomicApplicator->rollback();
            }
            
        } catch (\Exception $e) {
            // Transaction failed, rollback everything
            try {
                if ($this->atomicApplicator->isTransactionActive()) {
                    $this->atomicApplicator->rollback();
                }
            } catch (\Exception $rollbackError) {
                $result->addError("Rollback failed for $file: " . $rollbackError->getMessage());
            }
            
            $result->addError("Atomic fix failed for $file: " . $e->getMessage());
            
            // Mark all errors as unfixable
            foreach ($errors as $error) {
                $result->addUnfixableError($error);
            }
        }
    }
    
    /**
     * Fix errors using legacy (non-atomic) method
     * 
     * @param array<\PHPStanFixer\ValueObjects\Error> $errors
     */
    private function fixFileErrorsLegacy(string $file, array $errors, FixResult $result, bool $createBackup): void
    {
        $originalContent = file_get_contents($file);
        $content = $originalContent;
        $fixed = false;
        
        foreach ($errors as $error) {
            $fixer = $this->fixerRegistry->getFixerForError($error);
            
            if ($fixer === null) {
                $result->addUnfixableError($error);
                continue;
            }
            
            try {
                // Set type cache and current file for cache-aware fixers
                if ($fixer instanceof CacheAwareFixer && $this->typeCache) {
                    $fixer->setTypeCache($this->typeCache);
                    $fixer->setCurrentFile($file);
                }
                
                $newContent = $fixer->fix($content, $error);
                if ($newContent !== $content) {
                    $content = $newContent;
                    $fixed = true;
                    $result->addFixedError($error);
                }
            } catch (\Exception $e) {
                $result->addError("Failed to fix error in $file: " . $e->getMessage());
                $result->addUnfixableError($error);
            }
        }
        
        if ($fixed) {
            $backupFile = null;
            
            // Create backup only if requested
            if ($createBackup) {
                $backupFile = $file . '.phpstan-fixer.bak';
                file_put_contents($backupFile, $originalContent);
            }
            
            // Write fixed content
            file_put_contents($file, $content);
            
            $result->addFixedFile($file, $backupFile);
        }
    }
    
}