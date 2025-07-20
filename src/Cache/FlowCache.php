<?php

declare(strict_types=1);

namespace PHPStanFixer\Cache;

/**
 * Cache for storing data flow relationships between classes, methods, and properties
 * This captures the "how data flows" not just "what types are"
 */
class FlowCache
{
    private const CACHE_FILE = '.phpstan-fixer-flow-cache.json';
    private array $flows = [];
    private string $cacheFile;

    public function __construct(string $projectRoot)
    {
        $this->cacheFile = $projectRoot . '/' . self::CACHE_FILE;
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
        // Normalize class names (remove leading backslashes)
        $fromClass = ltrim($fromClass, '\\');
        $toClass = ltrim($toClass, '\\');
        
        $flowKey = "{$fromClass}::{$fromMethod}::\${$paramName}";
        $targetKey = "{$toClass}::\${$toProperty}";
        
        $this->flows['param_to_property'][$flowKey][] = [
            'target' => $targetKey,
            'timestamp' => time()
        ];
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
        // Normalize class names (remove leading backslashes)
        $fromClass = ltrim($fromClass, '\\');
        $toClass = ltrim($toClass, '\\');
        
        $sourceKey = "{$fromClass}::\${$fromProperty}";
        $targetKey = "{$toClass}::{$toMethod}::return";
        
        $this->flows['property_to_return'][$sourceKey][] = [
            'target' => $targetKey,
            'timestamp' => time()
        ];
    }

    /**
     * Get all properties that a parameter flows into
     */
    public function getParameterFlowTargets(string $className, string $methodName, string $paramName): array
    {
        $className = ltrim($className, '\\');
        $flowKey = "{$className}::{$methodName}::\${$paramName}";
        return $this->flows['param_to_property'][$flowKey] ?? [];
    }

    /**
     * Get all returns that a property flows into  
     */
    public function getPropertyFlowTargets(string $className, string $propertyName): array
    {
        $className = ltrim($className, '\\');
        $sourceKey = "{$className}::\${$propertyName}";
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
        file_put_contents($this->cacheFile, json_encode($this->flows, JSON_PRETTY_PRINT));
    }

    private function loadCache(): void
    {
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $this->flows = json_decode($content, true) ?? [];
        }
    }

    public function clear(): void
    {
        $this->flows = [];
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    /**
     * Get all flows for a specific type (for advanced inference)
     */
    public function getFlows(string $type): array
    {
        return $this->flows[$type] ?? [];
    }
} 