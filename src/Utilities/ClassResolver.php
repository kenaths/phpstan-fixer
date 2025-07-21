<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

/**
 * Utility for resolving class names and checking if classes exist in the project
 */
class ClassResolver
{
    private array $autoloadMap = [];
    private array $classCache = [];
    private string $projectRoot;
    private array $psr4Namespaces = [];
    
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->loadComposerAutoload();
    }
    
    /**
     * Check if a class exists in the project (local classes)
     */
    public function isLocalClass(string $className): bool
    {
        $className = ltrim($className, '\\');
        
        // Check cache first
        if (isset($this->classCache[$className])) {
            return $this->classCache[$className];
        }
        
        // Check if it's a built-in PHP class first
        if ($this->isBuiltinClass($className)) {
            $this->classCache[$className] = false;
            return false;
        }
        
        // Try to find the class file using PSR-4 autoloading
        $classFile = $this->findClassFile($className);
        if ($classFile && file_exists($classFile)) {
            $this->classCache[$className] = true;
            return true;
        }
        
        // Try to find the class by scanning project files
        $exists = $this->scanForClass($className);
        $this->classCache[$className] = $exists;
        
        return $exists;
    }
    
    /**
     * Resolve a relative class name to a fully qualified class name
     */
    public function resolveClassName(string $className, string $currentNamespace = ''): string
    {
        // Already fully qualified
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }
        
        // If we have a current namespace, try to resolve relative to it
        if ($currentNamespace) {
            $fullyQualified = $currentNamespace . '\\' . $className;
            if ($this->isLocalClass($fullyQualified)) {
                return $fullyQualified;
            }
        }
        
        // Try to find the class as-is
        if ($this->isLocalClass($className)) {
            return $className;
        }
        
        return $className;
    }
    
    /**
     * Get the namespace from a file
     */
    public function extractNamespaceFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Get all classes defined in a namespace
     */
    public function getClassesInNamespace(string $namespace): array
    {
        $classes = [];
        $namespace = trim($namespace, '\\');
        
        foreach ($this->psr4Namespaces as $prefix => $directories) {
            if (str_starts_with($namespace, $prefix)) {
                foreach ($directories as $directory) {
                    $namespacePath = $directory . '/' . str_replace('\\', '/', substr($namespace, strlen($prefix)));
                    if (is_dir($namespacePath)) {
                        $classes = array_merge($classes, $this->scanDirectoryForClasses($namespacePath, $namespace));
                    }
                }
            }
        }
        
        return $classes;
    }
    
    private function loadComposerAutoload(): void
    {
        $composerPath = $this->projectRoot . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }
        
        $composer = json_decode(file_get_contents($composerPath), true);
        if (!$composer) {
            return;
        }
        
        // Load PSR-4 autoload mappings
        if (isset($composer['autoload']['psr-4'])) {
            foreach ($composer['autoload']['psr-4'] as $namespace => $directories) {
                $namespace = trim($namespace, '\\');
                if (!is_array($directories)) {
                    $directories = [$directories];
                }
                
                $this->psr4Namespaces[$namespace] = array_map(function($dir) {
                    return $this->projectRoot . '/' . rtrim($dir, '/');
                }, $directories);
            }
        }
        
        // Load PSR-4 autoload-dev mappings for testing
        if (isset($composer['autoload-dev']['psr-4'])) {
            foreach ($composer['autoload-dev']['psr-4'] as $namespace => $directories) {
                $namespace = trim($namespace, '\\');
                if (!is_array($directories)) {
                    $directories = [$directories];
                }
                
                if (!isset($this->psr4Namespaces[$namespace])) {
                    $this->psr4Namespaces[$namespace] = [];
                }
                
                $this->psr4Namespaces[$namespace] = array_merge(
                    $this->psr4Namespaces[$namespace],
                    array_map(function($dir) {
                        return $this->projectRoot . '/' . rtrim($dir, '/');
                    }, $directories)
                );
            }
        }
    }
    
    private function findClassFile(string $className): ?string
    {
        $className = trim($className, '\\');
        
        foreach ($this->psr4Namespaces as $prefix => $directories) {
            if (str_starts_with($className, $prefix)) {
                $relativeClass = substr($className, strlen($prefix));
                $relativeClass = ltrim($relativeClass, '\\');
                
                foreach ($directories as $directory) {
                    $classFile = $directory . '/' . str_replace('\\', '/', $relativeClass) . '.php';
                    if (file_exists($classFile)) {
                        return $classFile;
                    }
                }
            }
        }
        
        return null;
    }
    
    private function scanForClass(string $className): bool
    {
        $className = trim($className, '\\');
        
        // Scan all PSR-4 directories
        foreach ($this->psr4Namespaces as $prefix => $directories) {
            foreach ($directories as $directory) {
                if ($this->scanDirectoryForClass($directory, $className)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function scanDirectoryForClass(string $directory, string $className): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                // Look for class declaration
                if (preg_match('/\b(?:class|interface|trait|enum)\s+(\w+)/i', $content, $matches)) {
                    $foundClass = $matches[1];
                    
                    // Get namespace
                    $namespace = $this->extractNamespaceFromFile($file->getPathname());
                    $fullClassName = $namespace ? $namespace . '\\' . $foundClass : $foundClass;
                    
                    if ($fullClassName === $className || $foundClass === $className) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function scanDirectoryForClasses(string $directory, string $namespace): array
    {
        $classes = [];
        
        if (!is_dir($directory)) {
            return $classes;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                // Look for class declaration
                if (preg_match_all('/\b(?:class|interface|trait|enum)\s+(\w+)/i', $content, $matches)) {
                    foreach ($matches[1] as $className) {
                        $classes[] = $namespace . '\\' . $className;
                    }
                }
            }
        }
        
        return $classes;
    }
    
    private function isBuiltinClass(string $className): bool
    {
        $builtinClasses = [
            'stdClass', 'Exception', 'ErrorException', 'Error', 'ParseError', 'TypeError',
            'ArgumentCountError', 'ArithmeticError', 'DivisionByZeroError', 'CompileError',
            'DateTime', 'DateTimeImmutable', 'DateInterval', 'DatePeriod', 'DateTimeZone',
            'Iterator', 'IteratorAggregate', 'Traversable', 'ArrayAccess', 'Countable',
            'Serializable', 'Closure', 'Generator', 'WeakReference', 'WeakMap',
            'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty', 'ReflectionFunction',
            'PDO', 'PDOStatement', 'SplFileInfo', 'SplFileObject', 'DirectoryIterator',
            'RecursiveDirectoryIterator', 'RecursiveIteratorIterator',
            // Add more as needed
        ];
        
        return in_array($className, $builtinClasses, true) || class_exists($className, false);
    }
    
    /**
     * Clear the class cache
     */
    public function clearCache(): void
    {
        $this->classCache = [];
    }
    
    /**
     * Get alternative type suggestions for invalid classes
     */
    public function suggestAlternativeTypes(string $invalidClassName): array
    {
        $suggestions = [];
        
        // Try to find similar class names in the project
        $className = ltrim($invalidClassName, '\\');
        
        // Extract namespace if present
        if (str_contains($className, '\\')) {
            $namespace = substr($className, 0, strrpos($className, '\\'));
            $classesInNamespace = $this->getClassesInNamespace($namespace);
            
            foreach ($classesInNamespace as $existingClass) {
                $existingClassName = substr($existingClass, strrpos($existingClass, '\\') + 1);
                
                // Simple similarity check
                if (levenshtein($className, $existingClassName) <= 2) {
                    $suggestions[] = $existingClass;
                }
            }
        }
        
        // Suggest common alternatives for common mistakes
        $commonAlternatives = [
            'Collection' => ['Illuminate\\Support\\Collection', 'Doctrine\\Common\\Collections\\Collection'],
            'Request' => ['Illuminate\\Http\\Request', 'Symfony\\Component\\HttpFoundation\\Request'],
            'Response' => ['Illuminate\\Http\\Response', 'Symfony\\Component\\HttpFoundation\\Response'],
            'Model' => ['Illuminate\\Database\\Eloquent\\Model'],
            'Builder' => ['Illuminate\\Database\\Eloquent\\Builder', 'Illuminate\\Database\\Query\\Builder'],
            'FromData' => ['mixed'], // Common fallback for data transfer objects
            'Component' => ['mixed'], // Common fallback for UI components
        ];
        
        $shortClassName = substr($className, strrpos($className, '\\') + 1);
        if (isset($commonAlternatives[$shortClassName])) {
            $suggestions = array_merge($suggestions, $commonAlternatives[$shortClassName]);
        }
        
        // Also check if the full class name or part of it matches common patterns
        if (str_contains($className, 'FromData')) {
            $suggestions[] = 'mixed';
        }
        
        return array_unique($suggestions);
    }
    
    /**
     * Get debug information about autoload mappings
     */
    public function getDebugInfo(): array
    {
        return [
            'projectRoot' => $this->projectRoot,
            'psr4Namespaces' => $this->psr4Namespaces,
            'cachedClasses' => array_keys($this->classCache),
        ];
    }
}