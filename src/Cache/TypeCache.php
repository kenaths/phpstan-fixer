<?php

declare(strict_types=1);

namespace PHPStanFixer\Cache;

class TypeCache
{
    private const CACHE_FILE = '.phpstan-fixer-cache.json';
    private array $cache = [];
    private string $cacheFile;
    private array $fileTimestamps = [];

    public function __construct(string $projectRoot)
    {
        $this->cacheFile = $projectRoot . '/' . self::CACHE_FILE;
        $this->loadCache();
    }

    public function setType(string $className, string $element, array $typeInfo): void
    {
        $key = $this->generateKey($className, $element);
        $this->cache[$key] = [
            'type' => $typeInfo,
            'timestamp' => time(),
            'file' => $this->getFilePathForClass($className),
        ];
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
            return $cacheEntry['type'];
        }

        // Remove stale entry
        unset($this->cache[$key]);
        return null;
    }

    public function save(): void
    {
        $data = [
            'version' => '1.0',
            'cache' => $this->cache,
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function clear(): void
    {
        $this->cache = [];
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    private function loadCache(): void
    {
        if (!file_exists($this->cacheFile)) {
            return;
        }

        $content = file_get_contents($this->cacheFile);
        $data = json_decode($content, true);

        if ($data && isset($data['cache'])) {
            $this->cache = $data['cache'];
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
}