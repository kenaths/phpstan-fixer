<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPStanFixer\Security\SecureFileOperations;

/**
 * Fixes generic type errors by using PHPStan's feedback to discover correct types
 */
class GenericTypeFixer extends AbstractFixer
{
    private ?PHPStanRunner $phpstanRunner = null;
    
    // Pre-compiled regex patterns for performance
    private const PATTERN_CAN_FIX = '/(?:extends generic class|implements generic interface).*but does not specify its types/i';
    private const PATTERN_EXTRACT_EXTENDS = '/Class ([^\\s]+) extends generic class ([^\\s]+) but does not specify its types: (.+)/';
    private const PATTERN_EXTRACT_IMPLEMENTS = '/Class ([^\\s]+) implements generic interface ([^\\s]+) but does not specify its types: (.+)/';
    private const PATTERN_TEMPLATE_EXTENDS = '/\/\\*\\*\\s*\\n\\s*\\*\\s*@template-extends\\s+[^<]+<[^>]+>\\s*\\n\\s*\\*\\//';
    private const PATTERN_SHOULD_RETURN = '/should return ([^\\s]+(?:\\|[^\\s]+)*) but returns/';
    private const PATTERN_CALLABLE_EXPECTS = '/expects callable\\(([^,\\s\\)]+),\\s*int\\)/';
    private const PATTERN_EXPECTS_TYPE = '/expects ([^\\s,]+(?:\\|[^\\s,]+)*),/';
    private const PATTERN_PARAMETER_EXPECTS = '/Parameter #\\d+ \\$\\w+ of method [^\\s]+ expects ([^\\s,]+(?:\\|[^\\s,]+)*)/';
    
    // Method pattern cache for type inference
    public static array $methodPatterns = [
        '/^get.*Column.*/' => 'Column',
        '/.*Searchable.*/' => 'Column',
        '/.*Sortable.*/' => 'Column', 
        '/.*DefaultSort.*/' => 'Column',
    ];

    public function __construct(?PHPStanRunner $phpstanRunner = null)
    {
        parent::__construct();
        $this->phpstanRunner = $phpstanRunner;
    }

    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return [
            'missingType.generics',
            'generic.missingType',
            'class.extendsGenericClassWithoutTypes',
        ];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match(self::PATTERN_CAN_FIX, $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        // Extract class and generic base class information
        $classInfo = $this->extractClassInfo($error->getMessage());
        if (!$classInfo) {
            return $content;
        }

        // Use iterative PHPStan approach to discover correct types
        return $this->fixWithIterativePHPStan($content, $classInfo, $error);
    }

    /**
     * @return array{class: string, baseClass: string, types: array<string>}|null
     */
    private function extractClassInfo(string $errorMessage): ?array
    {
        // Pattern: "Class X extends generic class Y but does not specify its types: TKey, TValue"
        if (preg_match(self::PATTERN_EXTRACT_EXTENDS, $errorMessage, $matches)) {
            return [
                'class' => $matches[1],
                'baseClass' => $matches[2],
                'types' => array_map('trim', explode(',', $matches[3])),
            ];
        }

        // Pattern: "Class X implements generic interface Y but does not specify its types: TKey, TValue"
        if (preg_match(self::PATTERN_EXTRACT_IMPLEMENTS, $errorMessage, $matches)) {
            return [
                'class' => $matches[1],
                'baseClass' => $matches[2],
                'types' => array_map('trim', explode(',', $matches[3])),
            ];
        }

        return null;
    }

    /**
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     */
    private function fixWithIterativePHPStan(string $content, array $classInfo, Error $error): string
    {
        $maxIterations = 5;
        $currentContent = $content;
        
        // Step 0: Try to infer types from the code structure first
        $structuralTypes = $this->inferTypesFromCodeStructure($content, $classInfo);
        
        // Step 1: Add initial generic type hint
        $initialTypes = $structuralTypes ?: $this->generateInitialTypes($classInfo);
        $currentContent = $this->addGenericTypeHint($currentContent, $classInfo, $initialTypes);
        
        // If no PHPStan runner available, return with structural inference
        if (!$this->phpstanRunner) {
            return $currentContent;
        }

        // Step 2: Iteratively refine types using PHPStan feedback
        for ($i = 0; $i < $maxIterations; $i++) {
            $feedback = $this->getPHPStanFeedback($currentContent, $error->getFile());
            
            if (!$feedback) {
                break; // No more errors, we're done
            }
            
            $refinedTypes = $this->refineTypesFromFeedback($feedback, $classInfo);
            
            if (!$refinedTypes) {
                break; // No useful feedback, stop
            }
            
            $currentContent = $this->updateGenericTypeHint($currentContent, $classInfo, $refinedTypes);
        }

        return $currentContent;
    }

    /**
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     * @return array<string>
     */
    private function generateInitialTypes(array $classInfo): array
    {
        $types = [];
        
        foreach ($classInfo['types'] as $typeParam) {
            // Make educated guesses based on common patterns
            if (str_contains(strtolower($typeParam), 'key')) {
                $types[] = 'int'; // Most collections use int keys
            } elseif (str_contains(strtolower($typeParam), 'value')) {
                $types[] = 'mixed'; // Start with mixed and let PHPStan tell us the actual type
            } else {
                $types[] = 'mixed';
            }
        }
        
        return $types;
    }

    /**
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     * @param array<string> $types
     */
    private function addGenericTypeHint(string $content, array $classInfo, array $types): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($classInfo, $types) extends NodeVisitorAbstract {
            private array $classInfo;
            private array $types;

            public function __construct(array $classInfo, array $types)
            {
                $this->classInfo = $classInfo;
                $this->types = $types;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $className = $node->name->toString();
                    $fullClassName = $this->classInfo['class'];
                    
                    // Check if this is the target class (handle both full and short names)
                    if ($className === $fullClassName || str_ends_with($fullClassName, '\\' . $className)) {
                        $this->addTemplateExtendsDoc($node);
                    }
                }
                
                return null;
            }

            private function addTemplateExtendsDoc(Node\Stmt\Class_ $classNode): void
            {
                $baseClass = $this->getShortClassName($this->classInfo['baseClass']);
                $typeList = implode(', ', $this->types);
                
                $templateDoc = "/**\n * @template-extends {$baseClass}<{$typeList}>\n */";
                
                // Add the docblock
                $classNode->setDocComment(new \PhpParser\Comment\Doc($templateDoc));
            }

            private function getShortClassName(string $fullClassName): string
            {
                $parts = explode('\\', $fullClassName);
                return end($parts);
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }

    /**
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     * @param array<string> $types
     */
    private function updateGenericTypeHint(string $content, array $classInfo, array $types): string
    {
        // For now, we'll update by replacing the existing docblock
        // This is a simplified approach - in production, we'd want more sophisticated parsing
        $baseClass = $this->getShortClassName($classInfo['baseClass']);
        $typeList = implode(', ', $types);
        
        $newDoc = "/**\n * @template-extends {$baseClass}<{$typeList}>\n */";
        
        // Find and replace the existing template-extends comment
        if (preg_match(self::PATTERN_TEMPLATE_EXTENDS, $content)) {
            return preg_replace(self::PATTERN_TEMPLATE_EXTENDS, $newDoc, $content);
        }
        
        return $content;
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * @return array<Error>|null
     */
    private function getPHPStanFeedback(string $content, string $filePath): ?array
    {
        if (!$this->phpstanRunner) {
            return null;
        }

        // Create secure temporary file
        $tempFile = SecureFileOperations::createTempFile('phpstan_generic_', '.php');

        try {
            SecureFileOperations::writeFile($tempFile, $content);
            
            $output = $this->phpstanRunner->analyze([$tempFile], 5); // Use level 5 for analysis
            $data = json_decode($output, true);
            
            if (!is_array($data) || !isset($data['files'])) {
                return null;
            }
            
            // Handle both real temp file paths and mock 'temp_file' key
            $fileKey = isset($data['files'][$tempFile]) ? $tempFile : 'temp_file';
            
            if (!isset($data['files'][$fileKey]['messages'])) {
                return null;
            }
            
            $errors = [];
            foreach ($data['files'][$fileKey]['messages'] as $message) {
                if (str_contains($message['message'], 'should return') || 
                    str_contains($message['message'], 'expects') ||
                    str_contains($message['message'], 'but returns')) {
                    $errors[] = new Error(
                        $filePath,
                        $message['line'],
                        $message['message'],
                        $message['identifier'] ?? null
                    );
                }
            }
            
            return empty($errors) ? null : $errors;
        } finally {
            SecureFileOperations::deleteFile($tempFile);
        }
    }

    /**
     * @param array<Error> $feedback
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     * @return array<string>|null
     */
    private function refineTypesFromFeedback(array $feedback, array $classInfo): ?array
    {
        $refinedTypes = [];
        
        foreach ($feedback as $error) {
            $message = $error->getMessage();
            
            // Pattern: "should return X but returns Y"
            if (preg_match(self::PATTERN_SHOULD_RETURN, $message, $matches)) {
                $expectedType = $matches[1];
                $refinedTypes[] = $this->normalizeType($expectedType);
                continue;
            }
            
            // Pattern: "expects callable(X, int): bool" - extract X as the value type
            if (preg_match(self::PATTERN_CALLABLE_EXPECTS, $message, $matches)) {
                $expectedType = $matches[1];
                $refinedTypes[] = $this->normalizeType($expectedType);
                continue;
            }
            
            // Pattern: "expects X, Y given"
            if (preg_match(self::PATTERN_EXPECTS_TYPE, $message, $matches)) {
                $expectedType = $matches[1];
                $refinedTypes[] = $this->normalizeType($expectedType);
                continue;
            }
            
            // Pattern: "Parameter #1 $item of method expects X"
            if (preg_match(self::PATTERN_PARAMETER_EXPECTS, $message, $matches)) {
                $expectedType = $matches[1];
                $refinedTypes[] = $this->normalizeType($expectedType);
                continue;
            }
        }
        
        if (empty($refinedTypes)) {
            return null;
        }
        
        // For collections, first type is usually key, second is value
        $result = [];
        if (count($classInfo['types']) >= 2) {
            $result[] = 'int'; // Most collections use int keys
            $result[] = $this->findMostSpecificType($refinedTypes);
        } else {
            $result[] = $this->findMostSpecificType($refinedTypes);
        }
        
        return $result;
    }

    private function normalizeType(string $type): string
    {
        // Remove null from union types for now (we'll handle nullable separately)
        $type = str_replace('|null', '', $type);
        $type = str_replace('null|', '', $type);
        
        // Handle fully qualified class names
        if (str_contains($type, '\\')) {
            $parts = explode('\\', $type);
            return end($parts);
        }
        
        return $type;
    }

    /**
     * @param array<string> $types
     */
    private function findMostSpecificType(array $types): string
    {
        // Remove duplicates
        $types = array_unique($types);
        
        // If we have only one type, return it
        if (count($types) === 1) {
            return $types[0];
        }
        
        // Prefer non-mixed types
        $nonMixedTypes = array_filter($types, fn($type) => $type !== 'mixed');
        
        if (!empty($nonMixedTypes)) {
            return $nonMixedTypes[0];
        }
        
        return 'mixed';
    }

    /**
     * Infer types from code structure analysis
     * 
     * @param array{class: string, baseClass: string, types: array<string>} $classInfo
     * @return array<string>|null
     */
    private function inferTypesFromCodeStructure(string $content, array $classInfo): ?array
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return null;
        }

        $visitor = new class($classInfo) extends NodeVisitorAbstract {
            private array $classInfo;
            private array $inferredTypes = [];
            private ?Node\Stmt\Class_ $targetClass = null;

            public function __construct(array $classInfo)
            {
                $this->classInfo = $classInfo;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $className = $node->name->toString();
                    $fullClassName = $this->classInfo['class'];
                    
                    // Check if this is the target class
                    if ($className === $fullClassName || str_ends_with($fullClassName, '\\' . $className)) {
                        $this->targetClass = $node;
                        $this->analyzeClass($node);
                    }
                }
                
                return null;
            }

            public function getInferredTypes(): array
            {
                return $this->inferredTypes;
            }

            private function analyzeClass(Node\Stmt\Class_ $class): void
            {
                $collectionItemTypes = [];
                
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        $types = $this->analyzeMethod($stmt);
                        $collectionItemTypes = array_merge($collectionItemTypes, $types);
                    }
                }
                
                // Remove duplicates and determine the most specific type
                $collectionItemTypes = array_unique($collectionItemTypes);
                
                if (!empty($collectionItemTypes)) {
                    $itemType = count($collectionItemTypes) === 1 
                        ? $collectionItemTypes[0] 
                        : $this->findMostSpecificType($collectionItemTypes);
                    
                    // For collections, assume int keys and inferred value type
                    $this->inferredTypes = ['int', $itemType];
                } else {
                    $this->inferredTypes = ['int', 'mixed'];
                }
            }

            /**
             * @return array<string>
             */
            private function analyzeMethod(Node\Stmt\ClassMethod $method): array
            {
                $types = [];
                
                // Check return type declarations
                if ($method->returnType instanceof Node\Name) {
                    $returnType = $method->returnType->toString();
                    if ($returnType !== 'bool' && $returnType !== 'void' && $returnType !== 'array') {
                        $types[] = $returnType;
                    }
                } elseif ($method->returnType instanceof Node\NullableType 
                         && $method->returnType->type instanceof Node\Name) {
                    $returnType = $method->returnType->type->toString();
                    if ($returnType !== 'bool' && $returnType !== 'void' && $returnType !== 'array') {
                        $types[] = $returnType;
                    }
                }
                
                // Analyze method body for collection usage patterns
                if ($method->stmts) {
                    $types = array_merge($types, $this->analyzeStatementsForCollectionUsage($method->stmts));
                }
                
                return $types;
            }

            /**
             * @param array<Node\Stmt> $stmts
             * @return array<string>
             */
            private function analyzeStatementsForCollectionUsage(array $stmts): array
            {
                $types = [];
                
                foreach ($stmts as $stmt) {
                    $types = array_merge($types, $this->analyzeNodeForCollectionUsage($stmt));
                }
                
                return $types;
            }

            /**
             * @return array<string>
             */
            private function analyzeNodeForCollectionUsage(Node $node): array
            {
                $types = [];
                $nodesToProcess = [$node];
                $processedCount = 0;
                $maxNodes = 10000; // Prevent infinite loops and excessive processing
                
                while (!empty($nodesToProcess) && $processedCount < $maxNodes) {
                    $currentNode = array_pop($nodesToProcess);
                    $processedCount++;
                    
                    // Analyze current node for collection patterns
                    if ($currentNode instanceof Node\Stmt\Return_ && $currentNode->expr instanceof Node\Expr\MethodCall) {
                        $types = array_merge($types, $this->analyzeMethodCallForCollectionType($currentNode->expr));
                    } elseif ($currentNode instanceof Node\Stmt\Expression && $currentNode->expr instanceof Node\Expr\Assign) {
                        if ($currentNode->expr->expr instanceof Node\Expr\MethodCall) {
                            $types = array_merge($types, $this->analyzeMethodCallForCollectionType($currentNode->expr->expr));
                        }
                    } elseif ($currentNode instanceof Node\Stmt\If_) {
                        if ($currentNode->cond instanceof Node\Expr\Assign && $currentNode->cond->expr instanceof Node\Expr\MethodCall) {
                            $types = array_merge($types, $this->analyzeMethodCallForCollectionType($currentNode->cond->expr));
                        }
                        
                        // Add if statements to processing stack
                        $nodesToProcess = array_merge($nodesToProcess, $currentNode->stmts);
                        if ($currentNode->else && $currentNode->else->stmts) {
                            $nodesToProcess = array_merge($nodesToProcess, $currentNode->else->stmts);
                        }
                    }
                    
                    // Add child nodes to processing stack (iterative instead of recursive)
                    foreach ($currentNode->getSubNodeNames() as $name) {
                        $subNode = $currentNode->$name;
                        if ($subNode instanceof Node) {
                            $nodesToProcess[] = $subNode;
                        } elseif (is_array($subNode)) {
                            foreach ($subNode as $child) {
                                if ($child instanceof Node) {
                                    $nodesToProcess[] = $child;
                                }
                            }
                        }
                    }
                }
                
                return $types;
            }

            /**
             * @return array<string>
             */
            private function analyzeMethodCallForCollectionType(Node\Expr\MethodCall $methodCall): array
            {
                $types = [];
                
                // Check if this is a collection method call on $this
                if ($methodCall->var instanceof Node\Expr\Variable 
                    && $methodCall->var->name === 'this'
                    && $methodCall->name instanceof Node\Identifier) {
                    
                    $methodName = $methodCall->name->toString();
                    
                    // Collection methods that work with individual items
                    if (in_array($methodName, ['first', 'filter', 'map', 'each', 'reject', 'partition'])) {
                        $types = array_merge($types, $this->analyzeCollectionMethodCallback($methodCall));
                    }
                }
                
                return $types;
            }

            /**
             * @return array<string>
             */
            private function analyzeCollectionMethodCallback(Node\Expr\MethodCall $methodCall): array
            {
                $types = [];
                
                // Look for callback functions in the method arguments
                foreach ($methodCall->args as $arg) {
                    if ($arg->value instanceof Node\Expr\Closure) {
                        $types = array_merge($types, $this->analyzeClosureForItemType($arg->value));
                    }
                }
                
                return $types;
            }

            /**
             * @return array<string>
             */
            private function analyzeClosureForItemType(Node\Expr\Closure $closure): array
            {
                $types = [];
                
                // Get the first parameter (usually the item)
                if (!empty($closure->params)) {
                    $param = $closure->params[0];
                    if ($param->var instanceof Node\Expr\Variable) {
                        $paramName = $param->var->name;
                        
                        // Look for method calls on the parameter
                        if ($closure->stmts) {
                            foreach ($closure->stmts as $stmt) {
                                $types = array_merge($types, $this->findMethodCallsOnVariable($stmt, $paramName));
                            }
                        }
                    }
                }
                
                return $types;
            }

            /**
             * @return array<string>
             */
            private function findMethodCallsOnVariable(Node $node, string $varName): array
            {
                $types = [];
                $nodesToProcess = [$node];
                $processedCount = 0;
                $maxNodes = 5000; // Reasonable limit for variable method analysis
                
                while (!empty($nodesToProcess) && $processedCount < $maxNodes) {
                    $currentNode = array_pop($nodesToProcess);
                    $processedCount++;
                    
                    if ($currentNode instanceof Node\Expr\MethodCall 
                        && $currentNode->var instanceof Node\Expr\Variable 
                        && $currentNode->var->name === $varName
                        && $currentNode->name instanceof Node\Identifier) {
                        
                        $methodName = $currentNode->name->toString();
                        
                        // Infer type from method name patterns
                        $type = $this->inferTypeFromMethodName($methodName);
                        if ($type) {
                            $types[] = $type;
                        }
                    }
                    
                    // Add child nodes to processing stack (iterative instead of recursive)
                    foreach ($currentNode->getSubNodeNames() as $name) {
                        $subNode = $currentNode->$name;
                        if ($subNode instanceof Node) {
                            $nodesToProcess[] = $subNode;
                        } elseif (is_array($subNode)) {
                            foreach ($subNode as $child) {
                                if ($child instanceof Node) {
                                    $nodesToProcess[] = $child;
                                }
                            }
                        }
                    }
                }
                
                return $types;
            }

            private function inferTypeFromMethodName(string $methodName): ?string
            {
                // Boolean methods don't reveal type - quick check first
                if (str_starts_with($methodName, 'is') || str_starts_with($methodName, 'has')) {
                    return null;
                }
                
                // Check cached patterns
                foreach (GenericTypeFixer::$methodPatterns as $pattern => $type) {
                    if (preg_match($pattern, $methodName)) {
                        return $type;
                    }
                }
                
                return null;
            }

            /**
             * @param array<string> $types
             */
            private function findMostSpecificType(array $types): string
            {
                // Remove duplicates
                $types = array_unique($types);
                
                // If we have only one type, return it
                if (count($types) === 1) {
                    return $types[0];
                }
                
                // Prefer non-mixed types
                $nonMixedTypes = array_filter($types, fn($type) => $type !== 'mixed');
                
                if (!empty($nonMixedTypes)) {
                    return $nonMixedTypes[0];
                }
                
                return 'mixed';
            }
        };

        $this->traverseWithVisitor($stmts, $visitor);
        
        $inferredTypes = $visitor->getInferredTypes();
        return !empty($inferredTypes) ? $inferredTypes : null;
    }
}