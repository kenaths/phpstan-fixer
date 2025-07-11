<?php

declare(strict_types=1);

namespace PHPStanFixer;

use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\Fixers\Registry\FixerRegistry;
use PHPStanFixer\Parser\ErrorParser;
use PHPStanFixer\Runner\PHPStanRunner;
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

    public function __construct(
        ?PHPStanRunner $phpstanRunner = null,
        ?ErrorParser $errorParser = null,
        ?FixerRegistry $fixerRegistry = null
    ) {
        $this->phpstanRunner = $phpstanRunner ?? new PHPStanRunner();
        $this->errorParser = $errorParser ?? new ErrorParser();
        $this->fixerRegistry = $fixerRegistry ?? new FixerRegistry();
        $this->filesystem = new Filesystem();
        
        $this->registerDefaultFixers();
    }

    /**
     * Fix PHPStan errors for the given paths at the specified level
     * 
     * @param array<string> $paths
     * @param array<string, mixed> $options
     */
    public function fix(array $paths, int $level, array $options = []): FixResult
    {
        $result = new FixResult();
        
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
        
        // Fix errors file by file
        foreach ($errorsByFile as $file => $fileErrors) {
            $this->fixFileErrors($file, $fileErrors, $result);
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
    private function fixFileErrors(string $file, array $errors, FixResult $result): void
    {
        if (!$this->filesystem->exists($file)) {
            $result->addError("File not found: $file");
            return;
        }
        
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
            // Create backup
            $backupFile = $file . '.phpstan-fixer.bak';
            file_put_contents($backupFile, $originalContent);
            
            // Write fixed content
            file_put_contents($file, $content);
            
            $result->addFixedFile($file, $backupFile);
        }
    }
}