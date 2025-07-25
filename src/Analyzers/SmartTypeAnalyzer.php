<?php

declare(strict_types=1);

namespace PHPStanFixer\Analyzers;

use PHPStanFixer\Cache\TypeCache;
use PHPStanFixer\Cache\FlowCache;
use PHPStanFixer\Utilities\ClassResolver;
use PHPStanFixer\Utilities\ProjectRootDetector;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Smart type analyzer that builds context-aware type information
 * by analyzing property assignments, method parameters, and usage patterns
 */
class SmartTypeAnalyzer
{
    private array $classProperties = [];
    private array $methodParameters = [];
    private array $propertyAssignments = [];
    private array $methodCalls = [];
    private array $typeCache = [];
    private array $processedClasses = [];
    private array $crossClassPropertyAccess = [];
    private array $constructorParameters = [];
    private ?TypeCache $externalCache = null;
    private ?string $currentFile = null;
    private ?SmartTypeVisitor $currentVisitor = null;
    private ?FlowCache $flowCache = null;
    private ?ClassResolver $classResolver = null;
    
    // Memory management settings
    private const MAX_CACHE_ENTRIES = 10000;
    private const MAX_CLASSES_PROCESSED = 1000;
    private const MAX_PROPERTY_ASSIGNMENTS = 5000;
    private const CLEANUP_THRESHOLD = 0.8; // Clean when 80% full
    
    // Memory usage tracking
    private int $totalPropertyAssignments = 0;
    private array $cacheAccessTimes = []; // For LRU tracking

    /**
     * Constructor to optionally set the external type cache
     */
    public function __construct(?TypeCache $externalCache = null, ?FlowCache $flowCache = null)
    {
        $this->externalCache = $externalCache;
        $this->flowCache = $flowCache;
        
        // Initialize ClassResolver with project root
        if ($this->currentFile) {
            $projectRoot = ProjectRootDetector::detectFromFilePath($this->currentFile);
            $this->classResolver = new ClassResolver($projectRoot);
        }
    }

    /**
     * Set the current file being analyzed
     */
    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        
        // Initialize or update ClassResolver when file changes
        if (!$this->classResolver) {
            $projectRoot = ProjectRootDetector::detectFromFilePath($file);
            $this->classResolver = new ClassResolver($projectRoot);
        }
    }

    public function getFlowCache(): ?FlowCache
    {
        return $this->flowCache;
    }

    /**
     * Analyze AST nodes to build type context
     *
     * @param Node[] $stmts
     */
    public function analyze(array $stmts): void
    {
        $traverser = new NodeTraverser();
        $visitor = new SmartTypeVisitor($this);
        $this->currentVisitor = $visitor;
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);
    }

    /**
     * Get inferred type for a property
     */
    public function getPropertyType(string $className, string $propertyName): ?string
    {
        // Check external cache first
        if ($this->externalCache) {
            $cachedType = $this->externalCache->getPropertyType($className, $propertyName);
            if ($cachedType !== null) {
                return $cachedType['phpDoc'] ?? $cachedType['native'] ?? null;
            }
        }

        $key = "{$className}::{$propertyName}";
        
        if (isset($this->typeCache[$key])) {
            // Update access time for LRU
            $this->cacheAccessTimes[$key] = time();
            return $this->typeCache[$key];
        }

        $type = $this->inferPropertyType($className, $propertyName);
        
        // Check cache size before adding
        if (count($this->typeCache) >= self::MAX_CACHE_ENTRIES) {
            $this->evictLeastRecentlyUsed();
        }
        
        $this->typeCache[$key] = $type;
        $this->cacheAccessTimes[$key] = time();
        
        // Save to external cache if available
        if ($this->externalCache && $type !== null && $this->currentFile) {
            $this->externalCache->setFilePathForClass($className, $this->currentFile);
            $this->externalCache->setPropertyType($className, $propertyName, $type);
        }
        
        return $type;
    }

    /**
     * Get inferred type for a method parameter
     */
    public function getParameterType(string $className, string $methodName, string $paramName): ?string
    {
        // Check external cache first
        if ($this->externalCache) {
            $methodParams = $this->externalCache->getMethodParameterTypes($className, $methodName);
            if ($methodParams !== null && isset($methodParams[$paramName])) {
                return $methodParams[$paramName]['phpDoc'] ?? $methodParams[$paramName]['native'] ?? null;
            }
        }

        $key = "{$className}::{$methodName}::{$paramName}";
        
        if (isset($this->typeCache[$key])) {
            return $this->typeCache[$key];
        }

        $type = $this->inferParameterType($className, $methodName, $paramName);
        
        // If traditional inference failed, try flow analysis
        if (!$type && $this->flowCache && $this->externalCache) {
            $type = $this->flowCache->inferParameterTypeFromFlow($className, $methodName, $paramName, $this->externalCache);
        }
        
        $this->typeCache[$key] = $type;
        
        return $type;
    }

    /**
     * Get inferred return type for a method
     */
    public function getReturnType(string $className, string $methodName): ?string
    {
        // Check external cache first
        if ($this->externalCache) {
            $returnType = $this->externalCache->getMethodReturnType($className, $methodName);
            if ($returnType !== null) {
                return $returnType['phpDoc'] ?? $returnType['native'] ?? null;
            }
        }

        $key = "{$className}::{$methodName}::return";
        
        if (isset($this->typeCache[$key])) {
            return $this->typeCache[$key];
        }

        $type = $this->inferReturnType($className, $methodName);
        
        // If traditional inference failed, try flow analysis
        if (!$type && $this->flowCache && $this->externalCache) {
            // Look for property-to-return flows
            $flows = $this->flowCache->getFlows('property_to_return');
            foreach ($flows as $sourceKey => $targets) {
                foreach ($targets as $target) {
                    $targetKey = $target['target'];
                    if ($targetKey === "{$className}::{$methodName}::return") {
                        // Parse source: "ClassName::$propertyName" 
                        if (preg_match('/^(.+)::\$(.+)$/', $sourceKey, $matches)) {
                            $sourceClass = ltrim($matches[1], '\\');
                            $sourceProperty = $matches[2];
                            
                            $propertyType = $this->externalCache->getPropertyType($sourceClass, $sourceProperty);
                            if ($propertyType) {
                                $type = $propertyType['phpDoc'] ?? $propertyType['native'] ?? null;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        $this->typeCache[$key] = $type;
        
        return $type;
    }

    /**
     * Register a property with its default value
     */
    public function registerProperty(string $className, string $propertyName, ?Node $defaultValue): void
    {
        $this->classProperties[$className][$propertyName] = [
            'defaultValue' => $defaultValue,
            'inferredType' => $this->inferTypeFromNode($defaultValue),
        ];
    }

    /**
     * Register a property assignment
     */
    public function registerPropertyAssignment(string $className, string $propertyName, Node $value, string $methodName): void
    {
        // Check memory limits before adding new assignments
        $this->enforceMemoryLimits();
        
        $this->propertyAssignments[$className][$propertyName][] = [
            'value' => $value,
            'inferredType' => $this->inferTypeFromNode($value),
            'method' => $methodName,
        ];
        
        $this->totalPropertyAssignments++;
    }

    /**
     * Register a method parameter
     */
    public function registerMethodParameter(string $className, string $methodName, string $paramName, ?Node $defaultValue): void
    {
        $this->methodParameters[$className][$methodName][$paramName] = [
            'defaultValue' => $defaultValue,
            'inferredType' => $this->inferTypeFromNode($defaultValue),
        ];
    }

    /**
     * Register a method call
     */
    public function registerMethodCall(string $className, string $methodName, array $arguments): void
    {
        $this->methodCalls[$className][$methodName][] = [
            'arguments' => $arguments,
            'argumentTypes' => array_map([$this, 'inferTypeFromNode'], $arguments),
        ];
    }

    /**
     * Register cross-class property access
     */
    public function registerCrossClassPropertyAccess(string $fromClass, string $toClass, string $propertyName, Node $value, string $methodName): void
    {
        $this->crossClassPropertyAccess[$fromClass][$toClass][$propertyName][] = [
            'value' => $value,
            'inferredType' => $this->inferTypeFromNode($value),
            'method' => $methodName,
        ];
    }

    /**
     * Register constructor parameter with type hint
     */
    public function registerConstructorParameter(string $className, string $paramName, ?string $typeHint, ?Node $defaultValue): void
    {
        $this->constructorParameters[$className][$paramName] = [
            'typeHint' => $typeHint,
            'defaultValue' => $defaultValue,
            'inferredType' => $typeHint ?: $this->inferTypeFromNode($defaultValue),
        ];
    }

    /**
     * Register method parameter with type hint
     */
    public function registerMethodParameterWithType(string $className, string $methodName, string $paramName, ?string $typeHint, ?Node $defaultValue): void
    {
        $this->methodParameters[$className][$methodName][$paramName] = [
            'typeHint' => $typeHint,
            'defaultValue' => $defaultValue,
            'inferredType' => $typeHint ?: $this->inferTypeFromNode($defaultValue),
        ];
    }

    /**
     * Infer type from a node
     */
    private function inferTypeFromNode(?Node $node): ?string
    {
        if ($node === null) {
            return null;
        }

        return match (true) {
            $node instanceof Node\Scalar\String_ => 'string',
            $node instanceof Node\Scalar\LNumber => 'int',
            $node instanceof Node\Scalar\DNumber => 'float',
            $node instanceof Node\Expr\Array_ => $this->inferArrayType($node),
            $node instanceof Node\Expr\ConstFetch => $this->inferConstType($node),
            $node instanceof Node\Expr\ClassConstFetch => 'string', // Most class constants are strings
            $node instanceof Node\Expr\New_ => $this->inferNewType($node),
            $node instanceof Node\Expr\Variable => $this->inferVariableType($node),
            $node instanceof Node\Expr\PropertyFetch => $this->inferPropertyFetchType($node),
            $node instanceof Node\Expr\MethodCall => $this->inferMethodCallType($node),
            default => null,
        };
    }

    /**
     * Infer array type with key and value analysis
     */
    private function inferArrayType(Node\Expr\Array_ $array): string
    {
        if (empty($array->items)) {
            return 'array';
        }

        $keyTypes = [];
        $valueTypes = [];

        foreach ($array->items as $item) {
            if ($item === null) continue;

            // Analyze key type
            if ($item->key !== null) {
                $keyType = $this->inferTypeFromNode($item->key);
                if ($keyType) {
                    $keyTypes[] = $keyType;
                }
            } else {
                $keyTypes[] = 'int'; // Numeric index
            }

            // Analyze value type
            $valueType = $this->inferTypeFromNode($item->value);
            if ($valueType) {
                $valueTypes[] = $valueType;
            }
        }

        $keyType = $this->unifyTypes($keyTypes) ?: 'mixed';
        $valueType = $this->unifyTypes($valueTypes) ?: 'mixed';

        // Return more specific array type
        if ($keyType === 'int' && $valueType !== 'mixed') {
            return "array<{$valueType}>";
        } elseif ($keyType !== 'mixed' && $valueType !== 'mixed') {
            return "array<{$keyType}, {$valueType}>";
        }

        return 'array';
    }

    /**
     * Infer type from constant fetch
     */
    private function inferConstType(Node\Expr\ConstFetch $const): string
    {
        $name = $const->name->toLowerString();
        return match ($name) {
            'true', 'false' => 'bool',
            'null' => 'null',
            default => 'mixed',
        };
    }

    /**
     * Infer type from new expression
     */
    private function inferNewType(Node\Expr\New_ $new): string
    {
        if ($new->class instanceof Node\Name) {
            $className = $new->class->toString();
            
            // If it's already fully qualified (starts with \), return as-is
            if (str_starts_with($className, '\\')) {
                return ltrim($className, '\\');
            }
            
            // Get current namespace from visitor
            $namespace = $this->currentVisitor?->getCurrentNamespace();
            
            // If we have a namespace, prepend it
            if ($namespace !== null) {
                return $namespace . '\\' . $className;
            }
            
            return $className;
        }
        return 'object';
    }

    /**
     * Infer type from variable
     */
    private function inferVariableType(Node\Expr\Variable $var): ?string
    {
        if ($var->name === 'this') {
            return 'self';
        }
        return null; // Need context to determine variable type
    }

    /**
     * Infer type from property fetch
     */
    private function inferPropertyFetchType(Node\Expr\PropertyFetch $fetch): ?string
    {
        if ($fetch->var instanceof Node\Expr\Variable && 
            $fetch->var->name === 'this' && 
            $fetch->name instanceof Node\Identifier) {
            
            // Try to get property type from current class analysis
            // This would need the current class context
            return null;
        }
        return null;
    }

    /**
     * Infer type from method call
     */
    private function inferMethodCallType(Node\Expr\MethodCall $call): ?string
    {
        // Common method return types
        if ($call->name instanceof Node\Identifier) {
            $methodName = $call->name->toString();
            return match ($methodName) {
                'count', 'sizeof' => 'int',
                'explode', 'array_merge', 'array_map', 'array_filter' => 'array',
                'implode', 'trim', 'strtolower', 'strtoupper' => 'string',
                'is_null', 'is_string', 'is_array', 'is_int', 'empty' => 'bool',
                default => null,
            };
        }
        return null;
    }

    /**
     * Unify multiple types into a single type
     */
    private function unifyTypes(array $types): ?string
    {
        $types = array_filter(array_unique($types));
        
        if (empty($types)) {
            return null;
        }

        if (count($types) === 1) {
            return reset($types);
        }

        // If we have multiple types, create a union type (limit to 3 types)
        if (count($types) <= 3) {
            sort($types);
            return implode('|', $types);
        }

        return 'mixed';
    }

    /**
     * Infer property type using all available information
     */
    private function inferPropertyType(string $className, string $propertyName): ?string
    {
        $types = [];

        // Check default value
        if (isset($this->classProperties[$className][$propertyName]['inferredType'])) {
            $defaultType = $this->classProperties[$className][$propertyName]['inferredType'];
            if ($defaultType) {
                $types[] = $defaultType;
            }
        }

        // Check assignments
        if (isset($this->propertyAssignments[$className][$propertyName])) {
            foreach ($this->propertyAssignments[$className][$propertyName] as $assignment) {
                if ($assignment['inferredType']) {
                    $types[] = $assignment['inferredType'];
                }
            }
        }

        // Check if property is used in method parameters (reverse inference)
        foreach ($this->methodParameters[$className] ?? [] as $methodName => $parameters) {
            foreach ($parameters as $paramName => $paramInfo) {
                if ($this->isPropertyUsedInParameter($className, $propertyName, $methodName, $paramName)) {
                    // If we know the parameter type, it might give us a hint about the property
                    if ($paramInfo['inferredType']) {
                        $types[] = $paramInfo['inferredType'];
                    }
                }
            }
        }

        // Check cross-class assignments TO this property
        foreach ($this->crossClassPropertyAccess as $fromClass => $toClasses) {
            foreach ($toClasses as $toClass => $properties) {
                if ($toClass === $className && isset($properties[$propertyName])) {
                    foreach ($properties[$propertyName] as $assignment) {
                        if ($assignment['inferredType']) {
                            $types[] = $assignment['inferredType'];
                        }
                    }
                }
            }
        }

        return $this->unifyTypes($types);
    }

    /**
     * Infer parameter type using property context
     */
    private function inferParameterType(string $className, string $methodName, string $paramName): ?string
    {
        $types = [];

        // Check constructor parameters first
        if ($methodName === '__construct' && isset($this->constructorParameters[$className][$paramName]['inferredType'])) {
            $constructorType = $this->constructorParameters[$className][$paramName]['inferredType'];
            if ($constructorType) {
                $types[] = $constructorType;
            }
        }

        // Check default value
        if (isset($this->methodParameters[$className][$methodName][$paramName]['inferredType'])) {
            $defaultType = $this->methodParameters[$className][$methodName][$paramName]['inferredType'];
            if ($defaultType) {
                $types[] = $defaultType;
            }
        }

        // Check if parameter is used to assign to a property
        foreach ($this->propertyAssignments[$className] ?? [] as $propertyName => $assignments) {
            foreach ($assignments as $assignment) {
                if ($assignment['method'] === $methodName && 
                    $this->isParameterUsedInAssignment($assignment['value'], $paramName)) {
                    
                    // Get property type
                    $propertyType = $this->getPropertyType($className, $propertyName);
                    if ($propertyType) {
                        $types[] = $propertyType;
                    }
                }
            }
        }

        // Check cross-class property assignments for type hints
        foreach ($this->crossClassPropertyAccess[$className] ?? [] as $toClass => $properties) {
            foreach ($properties as $propertyName => $assignments) {
                foreach ($assignments as $assignment) {
                    if ($assignment['method'] === $methodName && 
                        $this->isParameterUsedInAssignment($assignment['value'], $paramName)) {
                        
                        // Get target property type
                        $targetPropertyType = $this->getPropertyType($toClass, $propertyName);
                        if ($targetPropertyType) {
                            $types[] = $targetPropertyType;
                        }
                    }
                }
            }
        }

        // Check method calls for this parameter
        if (isset($this->methodCalls[$className][$methodName])) {
            foreach ($this->methodCalls[$className][$methodName] as $call) {
                // Analyze argument types at call site
                foreach ($call['argumentTypes'] as $argType) {
                    if ($argType) {
                        $types[] = $argType;
                    }
                }
            }
        }

        return $this->unifyTypes($types);
    }

    /**
     * Infer return type using method analysis
     */
    private function inferReturnType(string $className, string $methodName): ?string
    {
        // This would analyze return statements in the method
        // For now, return null to use existing logic
        return null;
    }

    /**
     * Check if a property is used in a parameter context
     */
    private function isPropertyUsedInParameter(string $className, string $propertyName, string $methodName, string $paramName): bool
    {
        // This would need more sophisticated analysis
        // For now, check if names are similar (simple heuristic)
        return $propertyName === $paramName || 
               str_contains($paramName, $propertyName) || 
               str_contains($propertyName, $paramName);
    }

    /**
     * Check if a parameter is used in an assignment
     */
    private function isParameterUsedInAssignment(Node $assignmentValue, string $paramName): bool
    {
        if ($assignmentValue instanceof Node\Expr\Variable) {
            return $assignmentValue->name === $paramName;
        }
        
        // Could traverse the node to find variable usage
        return false;
    }

    /**
     * Clear cache for a specific class (useful for iterative analysis)
     */
    public function clearClassCache(string $className): void
    {
        foreach ($this->typeCache as $key => $value) {
            if (str_starts_with($key, $className . '::')) {
                unset($this->typeCache[$key]);
            }
        }
    }

    /**
     * Get statistics about analyzed types
     */
    public function getAnalysisStats(): array
    {
        return [
            'classes' => count($this->processedClasses),
            'properties' => array_sum(array_map('count', $this->classProperties)),
            'methods' => array_sum(array_map('count', $this->methodParameters)),
            'cached_types' => count($this->typeCache),
        ];
    }

    /**
     * Get constructor parameter type
     */
    public function getConstructorParameterType(string $className, string $paramName): ?string
    {
        return $this->constructorParameters[$className][$paramName]['inferredType'] ?? null;
    }

    /**
     * Get debug info about constructor parameters
     */
    public function getDebugInfo(): array
    {
        return [
            'constructorParameters' => $this->constructorParameters,
            'methodParameters' => $this->methodParameters,
            'classProperties' => $this->classProperties,
            'crossClassPropertyAccess' => $this->crossClassPropertyAccess,
            'memoryUsage' => $this->getMemoryUsage(),
        ];
    }
    
    /**
     * Get current memory usage statistics
     */
    public function getMemoryUsage(): array
    {
        return [
            'processedClasses' => count($this->processedClasses),
            'cachedTypes' => count($this->typeCache),
            'totalPropertyAssignments' => $this->totalPropertyAssignments,
            'classProperties' => array_sum(array_map('count', $this->classProperties)),
            'methodParameters' => array_sum(array_map(function($methods) {
                return array_sum(array_map('count', $methods));
            }, $this->methodParameters)),
            'memoryUsageBytes' => memory_get_usage(),
            'peakMemoryUsageBytes' => memory_get_peak_usage(),
        ];
    }
    
    /**
     * Enforce memory limits and clean up if necessary
     */
    private function enforceMemoryLimits(): void
    {
        // Check if we need cleanup
        $needsCleanup = (
            count($this->processedClasses) >= self::MAX_CLASSES_PROCESSED * self::CLEANUP_THRESHOLD ||
            count($this->typeCache) >= self::MAX_CACHE_ENTRIES * self::CLEANUP_THRESHOLD ||
            $this->totalPropertyAssignments >= self::MAX_PROPERTY_ASSIGNMENTS * self::CLEANUP_THRESHOLD
        );
        
        if ($needsCleanup) {
            $this->performMemoryCleanup();
        }
    }
    
    /**
     * Perform memory cleanup when limits are approached
     */
    private function performMemoryCleanup(): void
    {
        // Clean up old property assignments (keep only recent ones)
        if ($this->totalPropertyAssignments >= self::MAX_PROPERTY_ASSIGNMENTS * self::CLEANUP_THRESHOLD) {
            $this->cleanupPropertyAssignments();
        }
        
        // Clean up processed classes if too many
        if (count($this->processedClasses) >= self::MAX_CLASSES_PROCESSED * self::CLEANUP_THRESHOLD) {
            $this->cleanupProcessedClasses();
        }
        
        // Evict old cache entries
        if (count($this->typeCache) >= self::MAX_CACHE_ENTRIES * self::CLEANUP_THRESHOLD) {
            $this->evictLeastRecentlyUsed();
        }
    }
    
    /**
     * Clean up old property assignments to free memory
     */
    private function cleanupPropertyAssignments(): void
    {
        $cleanedCount = 0;
        $maxToKeep = (int)(self::MAX_PROPERTY_ASSIGNMENTS * 0.6); // Keep 60% of max
        
        foreach ($this->propertyAssignments as $className => $properties) {
            foreach ($properties as $propertyName => $assignments) {
                if (count($assignments) > 10) { // If more than 10 assignments for a property
                    // Keep only the most recent 5
                    $this->propertyAssignments[$className][$propertyName] = array_slice($assignments, -5);
                    $cleanedCount += count($assignments) - 5;
                }
            }
        }
        
        $this->totalPropertyAssignments = max(0, $this->totalPropertyAssignments - $cleanedCount);
    }
    
    /**
     * Clean up old processed classes entries
     */
    private function cleanupProcessedClasses(): void
    {
        // Remove oldest 40% of processed classes
        $toRemove = (int)(count($this->processedClasses) * 0.4);
        $classNames = array_keys($this->processedClasses);
        
        for ($i = 0; $i < $toRemove && $i < count($classNames); $i++) {
            unset($this->processedClasses[$classNames[$i]]);
        }
    }
    
    /**
     * Evict least recently used cache entries
     */
    private function evictLeastRecentlyUsed(): void
    {
        if (empty($this->cacheAccessTimes)) {
            // If no access times recorded, remove oldest 20% of cache
            $toRemove = (int)(count($this->typeCache) * 0.2);
            $keys = array_keys($this->typeCache);
            
            for ($i = 0; $i < $toRemove; $i++) {
                unset($this->typeCache[$keys[$i]]);
            }
            return;
        }
        
        // Sort by access time (oldest first)
        asort($this->cacheAccessTimes);
        
        // Remove oldest 20% entries
        $toRemove = (int)(count($this->typeCache) * 0.2);
        $removed = 0;
        
        foreach ($this->cacheAccessTimes as $key => $accessTime) {
            if ($removed >= $toRemove) {
                break;
            }
            
            unset($this->typeCache[$key]);
            unset($this->cacheAccessTimes[$key]);
            $removed++;
        }
    }
    
    /**
     * Force cleanup of all internal caches
     */
    public function clearInternalCaches(): void
    {
        $this->typeCache = [];
        $this->cacheAccessTimes = [];
        $this->processedClasses = [];
        $this->totalPropertyAssignments = 0;
        
        // Optionally clear data structures but keep recent ones
        foreach ($this->propertyAssignments as $className => $properties) {
            foreach ($properties as $propertyName => $assignments) {
                // Keep only the most recent assignment for each property
                $this->propertyAssignments[$className][$propertyName] = array_slice($assignments, -1);
            }
        }
    }

    /**
     * Save all discovered types to external cache
     */
    public function saveDiscoveredTypes(): void
    {
        if (!$this->externalCache || !$this->currentFile) {
            return;
        }

        // Save property types
        foreach ($this->classProperties as $className => $properties) {
            $this->externalCache->setFilePathForClass($className, $this->currentFile);
            
            foreach ($properties as $propertyName => $propertyInfo) {
                if ($propertyInfo['inferredType']) {
                    $this->externalCache->setPropertyType(
                        $className,
                        $propertyName,
                        $propertyInfo['inferredType']
                    );
                }
            }
        }

        // Save method parameter and return types
        foreach ($this->methodParameters as $className => $methods) {
            foreach ($methods as $methodName => $parameters) {
                $paramTypes = [];
                foreach ($parameters as $paramName => $paramInfo) {
                    if (isset($paramInfo['inferredType']) && $paramInfo['inferredType']) {
                        $paramTypes[$paramName] = [
                            'phpDoc' => $paramInfo['inferredType'],
                            'native' => $paramInfo['typeHint'] ?? null,
                        ];
                    }
                }
                
                if (!empty($paramTypes)) {
                    $returnType = $this->getReturnType($className, $methodName);
                    $this->externalCache->setMethodTypes(
                        $className,
                        $methodName,
                        $paramTypes,
                        $returnType
                    );
                }
            }
        }
    }
}

/**
 * Visitor to collect type information from AST
 */
class SmartTypeVisitor extends NodeVisitorAbstract
{
    private SmartTypeAnalyzer $analyzer;
    private ?string $currentClass = null;
    private ?string $currentMethod = null;
    private ?string $currentNamespace = null;

    public function __construct(SmartTypeAnalyzer $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    public function getCurrentNamespace(): ?string
    {
        return $this->currentNamespace;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : null;
        } elseif ($node instanceof Node\Stmt\Class_) {
            if ($node->name) {
                $className = $node->name->toString();
                // Build fully qualified class name
                if ($this->currentNamespace) {
                    $this->currentClass = $this->currentNamespace . '\\' . $className;
                } else {
                    $this->currentClass = $className;
                }
            } else {
                $this->currentClass = null;
            }
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->toString();
            
            // Register method parameters
            if ($this->currentClass) {
                foreach ($node->params as $param) {
                    if ($param->var instanceof Node\Expr\Variable) {
                        // Check if it's a constructor with type hints
                        if ($this->currentMethod === '__construct' && $param->type) {
                            $typeHint = $this->getTypeHintString($param->type);
                            $this->analyzer->registerConstructorParameter(
                                $this->currentClass,
                                $param->var->name,
                                $typeHint,
                                $param->default
                            );
                        }
                        
                        // Always register method parameters (with type hints if available)
                        $typeHint = $param->type ? $this->getTypeHintString($param->type) : null;
                        $this->analyzer->registerMethodParameterWithType(
                            $this->currentClass,
                            $this->currentMethod,
                            $param->var->name,
                            $typeHint,
                            $param->default
                        );
                    }
                }
            }
        } elseif ($node instanceof Node\Stmt\Property && $this->currentClass) {
            // Register class properties
            foreach ($node->props as $prop) {
                $this->analyzer->registerProperty(
                    $this->currentClass,
                    $prop->name->toString(),
                    $prop->default
                );
            }
        } elseif ($node instanceof Node\Expr\Assign && $this->currentClass && $this->currentMethod) {
            // Register property assignments
            if ($node->var instanceof Node\Expr\PropertyFetch &&
                $node->var->name instanceof Node\Identifier) {
                
                if ($node->var->var instanceof Node\Expr\Variable &&
                    $node->var->var->name === 'this') {
                    // This is a $this->property assignment
                    $this->analyzer->registerPropertyAssignment(
                        $this->currentClass,
                        $node->var->name->toString(),
                        $node->expr,
                        $this->currentMethod
                    );
                } elseif ($node->var->var instanceof Node\Expr\PropertyFetch &&
                    $node->var->var->var instanceof Node\Expr\Variable &&
                    $node->var->var->var->name === 'this' &&
                    $node->var->var->name instanceof Node\Identifier) {
                    // This is a $this->objectProperty->property assignment (cross-class)
                    $objectProperty = $node->var->var->name->toString();
                    $targetProperty = $node->var->name->toString();
                    
                    // Try to determine the type of the object property
                    $objectType = $this->getObjectTypeFromConstructor($objectProperty);
                    if ($objectType) {
                        $this->analyzer->registerCrossClassPropertyAccess(
                            $this->currentClass,
                            $objectType,
                            $targetProperty,
                            $node->expr,
                            $this->currentMethod
                        );
                        
                        // Record flow for parameters -> properties
                        if ($node->expr instanceof Node\Expr\Variable && 
                            $this->currentMethod && 
                            $this->currentClass) {
                            
                            $flowCache = $this->analyzer->getFlowCache();
                            if ($flowCache) {
                                $flowCache->recordParameterToPropertyFlow(
                                    $this->currentClass,
                                    $this->currentMethod,
                                    $node->expr->name,
                                    $objectType,
                                    $targetProperty
                                );
                            }
                        }
                    }
                }
            }
        } elseif ($node instanceof Node\Expr\MethodCall && $this->currentClass) {
            // Register method calls
            if ($node->name instanceof Node\Identifier) {
                $this->analyzer->registerMethodCall(
                    $this->currentClass,
                    $node->name->toString(),
                    $node->args
                );
            }
        } elseif ($node instanceof Node\Stmt\Return_ && $this->currentMethod && $this->currentClass) {
            // Handle return statements for flow analysis
            if ($node->expr instanceof Node\Expr\PropertyFetch &&
                $node->expr->var instanceof Node\Expr\PropertyFetch &&
                $node->expr->var->var instanceof Node\Expr\Variable &&
                $node->expr->var->var->name === 'this' &&
                $node->expr->var->name instanceof Node\Identifier &&
                $node->expr->name instanceof Node\Identifier) {
                
                // This is a return $this->objectProperty->property; pattern
                $objectProperty = $node->expr->var->name->toString();
                $targetProperty = $node->expr->name->toString();
                
                // Get the type of the object property to determine target class
                $objectType = $this->getObjectTypeFromConstructor($objectProperty);
                if ($objectType) {
                    $flowCache = $this->analyzer->getFlowCache();
                    if ($flowCache) {
                        $flowCache->recordPropertyToReturnFlow(
                            $objectType,
                            $targetProperty,
                            $this->currentClass,
                            $this->currentMethod
                        );
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = null;
        }

        return null;
    }

    /**
     * Get type hint string from type node
     */
    private function getTypeHintString(Node $typeNode): ?string
    {
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        } elseif ($typeNode instanceof Node\Identifier) {
            return $typeNode->toString();
        } elseif ($typeNode instanceof Node\UnionType) {
            $types = [];
            foreach ($typeNode->types as $type) {
                $typeStr = $this->getTypeHintString($type);
                if ($typeStr) {
                    $types[] = $typeStr;
                }
            }
            return implode('|', $types);
        } elseif ($typeNode instanceof Node\NullableType) {
            $innerType = $this->getTypeHintString($typeNode->type);
            return $innerType ? $innerType . '|null' : null;
        }
        return null;
    }

    /**
     * Get object type from constructor parameter
     */
    private function getObjectTypeFromConstructor(string $propertyName): ?string
    {
        if ($this->currentClass) {
            // First check constructor parameters
            $constructorType = $this->analyzer->getConstructorParameterType($this->currentClass, $propertyName);
            if ($constructorType) {
                return $constructorType;
            }
            
            // Then check property types (for cases like $this->model = new DataModel())
            $propertyType = $this->analyzer->getPropertyType($this->currentClass, $propertyName);
            if ($propertyType) {
                return $propertyType;
            }
        }
        return null;
    }
    
    /**
     * Validate if a class type is valid in the current project context
     */
    public function validateClassType(string $className): bool
    {
        if (!$this->classResolver) {
            return true; // If no resolver, assume valid
        }
        
        $className = ltrim($className, '\\');
        
        // Remove generic type parameters if present
        if (str_contains($className, '<')) {
            $className = substr($className, 0, strpos($className, '<'));
        }
        
        // Check built-in PHP types
        $builtinTypes = [
            'array', 'string', 'int', 'float', 'bool', 'object', 'resource', 'null',
            'mixed', 'void', 'never', 'true', 'false', 'callable', 'iterable',
            'self', 'parent', 'static'
        ];
        
        if (in_array(strtolower($className), $builtinTypes, true)) {
            return true;
        }
        
        // Check if it's a local class using ClassResolver
        return $this->classResolver->isLocalClass($className);
    }
    
    /**
     * Resolve a class name to its fully qualified name
     */
    public function resolveClassName(string $className): string
    {
        if (!$this->classResolver) {
            return $className;
        }
        
        // Get current namespace from visitor
        $namespace = $this->currentVisitor?->getCurrentNamespace();
        
        return $this->classResolver->resolveClassName($className, $namespace ?? '');
    }
    
    /**
     * Get alternative type suggestions for invalid classes
     */
    public function suggestAlternativeTypes(string $invalidClassName): array
    {
        if (!$this->classResolver) {
            return [];
        }
        
        return $this->classResolver->suggestAlternativeTypes($invalidClassName);
    }
}
