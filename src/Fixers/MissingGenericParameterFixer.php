<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Security\SecureFileOperations;
use PHPStanFixer\Utilities\GenericTypeHandler;
use PHPStanFixer\Utilities\IndentationHelper;

/**
 * Fixes missing generic types for parameters of generic classes
 */
class MissingGenericParameterFixer extends AbstractFixer
{
    private ?PHPStanRunner $phpstanRunner = null;
    
    // Pre-compiled regex patterns for performance
    private const PATTERN_CAN_FIX = '/has parameter \\$\\w+ with generic class .* but does not specify its types/';
    private const PATTERN_EXTRACT_INFO = '/Method (.*?)::(\\w+)\\(\\) has parameter \\$(\\w+) with generic class (.*?) but does not specify its types: (.*)/';
    private const PATTERN_SHOULD_RETURN = '/should return ([^\\s]+(?:\\|[^\\s]+)*) but returns/';
    private const PATTERN_CALLABLE_GIVEN = '/expects callable\\(([^,\\s\\)]+),\\s*int\\):\\s*bool,\\s*callable\\(([^,\\s\\)]+),\\s*int\\):\\s*bool given/';
    private const PATTERN_CALLABLE_EXPECTS = '/expects callable\\(([^,\\s\\)]+),\\s*int\\)/';
    private const PATTERN_EXPECTS_TYPE = '/expects ([^\\s,]+(?:\\|[^\\s,]+)*),/';
    private const PATTERN_PARAMETER_EXPECTS = '/Parameter #\\d+ \\$\\w+ of method [^\\s]+ expects ([^\\s,]+(?:\\|[^\\s,]+)*)/';
    private const PATTERN_UPDATE_DOC = '/\\* @param %s<[^>]+> \\$%s/';

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
        return ['missing_generic_param'];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match(self::PATTERN_CAN_FIX, $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $matches = [];
        if (!preg_match(self::PATTERN_EXTRACT_INFO, $error->getMessage(), $matches)) {
            return $content;
        }

        [, $className, $methodName, $paramName, $genericClass, $typesStr] = $matches;
        $templateTypes = array_map('trim', explode(',', $typesStr));

        $paramInfo = [
            'class' => $className,
            'method' => $methodName,
            'param' => $paramName,
            'genericClass' => $genericClass,
            'templateTypes' => $templateTypes,
        ];

        return $this->fixWithIterativePHPStan($content, $paramInfo, $error);
    }

    /**
     * @param array{class: string, method: string, param: string, genericClass: string, templateTypes: array<string>} $paramInfo
     */
    private function fixWithIterativePHPStan(string $content, array $paramInfo, Error $error): string
    {
        $maxIterations = 5;
        $currentContent = $content;

        // Step 1: Add initial generic type hint with mixed
        $initialTypes = array_fill(0, count($paramInfo['templateTypes']), 'mixed');
        $currentContent = $this->addGenericParamDoc($currentContent, $paramInfo, $initialTypes);

        // If no PHPStan runner available, return with initial
        if (!$this->phpstanRunner) {
            return $currentContent;
        }

        // Step 2: Iteratively refine types using PHPStan feedback
        for ($i = 0; $i < $maxIterations; $i++) {
            $feedback = $this->getPHPStanFeedback($currentContent, $error->getFile());

            if (!$feedback) {
                break; // No more errors
            }

            $refinedTypes = $this->refineTypesFromFeedback($feedback, $paramInfo);

            if (!$refinedTypes) {
                break; // No useful feedback
            }

            $currentContent = $this->updateGenericParamDoc($currentContent, $paramInfo, $refinedTypes);
        }

        return $currentContent;
    }

    /**
     * @param array{class: string, method: string, param: string, genericClass: string, templateTypes: array<string>} $paramInfo
     * @param array<string> $types
     */
    private function addGenericParamDoc(string $content, array $paramInfo, array $types): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($paramInfo, $types, $content) extends NodeVisitorAbstract {
            private array $paramInfo;
            private array $types;
            private string $originalContent;

            public function __construct(array $paramInfo, array $types, string $originalContent)
            {
                $this->paramInfo = $paramInfo;
                $this->types = $types;
                $this->originalContent = $originalContent;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $this->paramInfo['method']) {
                    // Use GenericTypeHandler to get better type analysis
                    $analysis = GenericTypeHandler::analyzeGenericType(
                        $this->paramInfo['genericClass'], 
                        $this->paramInfo['method'] . ' ' . $this->paramInfo['param']
                    );
                    
                    // Use improved types if available, otherwise use provided types
                    $finalTypes = $analysis['isGeneric'] ? 
                        [$analysis['valueType']] : 
                        $this->types;
                    
                    $baseClass = $this->getShortClassName($this->paramInfo['genericClass']);
                    $typeList = implode(', ', $finalTypes);
                    $paramName = $this->paramInfo['param'];

                    // Create PHPDoc with proper indentation
                    $docContent = "@param {$baseClass}<{$typeList}> \${$paramName}";
                    
                    // Get base indentation for proper formatting
                    $baseIndentation = IndentationHelper::getNodeIndentation($node, $this->originalContent);
                    $formattedDoc = IndentationHelper::formatDocBlock($docContent, $baseIndentation);
                    
                    $node->setDocComment(new Doc($formattedDoc));
                }
                return null;
            }

            private function getShortClassName(string $fullName): string
            {
                $parts = explode('\\', $fullName);
                return end($parts);
            }
        };

        return $this->fixWithFormatPreservation($content, $visitor);
    }

    /**
     * @param array{class: string, method: string, param: string, genericClass: string, templateTypes: array<string>} $paramInfo
     * @param array<string> $types
     */
    private function updateGenericParamDoc(string $content, array $paramInfo, array $types): string
    {
        $baseClass = $this->getShortClassName($paramInfo['genericClass']);
        $typeList = implode(', ', $types);
        $paramName = $paramInfo['param'];

        $newDocLine = " * @param {$baseClass}<{$typeList}> \${$paramName}";

        // Find and replace the existing @param line
        $pattern = sprintf(self::PATTERN_UPDATE_DOC, preg_quote($baseClass, '/'), preg_quote($paramName, '/'));

        return preg_replace($pattern, $newDocLine, $content, 1);
    }

    private function getShortClassName(string $fullName): string
    {
        $parts = explode('\\', $fullName);
        return end($parts);
    }

    /**
     * @return array<Error>|null
     */
    private function getPHPStanFeedback(string $content, string $filePath): ?array
    {
        $tempFile = SecureFileOperations::createTempFile('phpstan_generic_', '.php');

        try {
            SecureFileOperations::writeFile($tempFile, $content);
            
            $output = $this->phpstanRunner->analyze([$tempFile], 5);
            $data = json_decode($output, true);

            if (!is_array($data) || !isset($data['files'])) {
                return null;
            }

            $fileKey = isset($data['files'][$tempFile]) ? $tempFile : (isset($data['files']['temp_file']) ? 'temp_file' : null);

            if ($fileKey === null || !isset($data['files'][$fileKey]['messages'])) {
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
     * @param array{class: string, method: string, param: string, genericClass: string, templateTypes: array<string>} $paramInfo
     * @return array<string>|null
     */
    private function refineTypesFromFeedback(array $feedback, array $paramInfo): ?array
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

            // Pattern: "expects callable(X, int): bool, callable(Y, int): bool given"
            if (preg_match(self::PATTERN_CALLABLE_GIVEN, $message, $matches)) {
                $expectedType = $matches[1];
                $givenType = $matches[2];
                // Use the given type as it's more specific than the expected mixed
                $refinedTypes[] = $this->normalizeType($givenType);
                continue;
            }
            
            // Pattern: "expects callable(X, int): bool"
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
        if (count($paramInfo['templateTypes']) >= 2 && str_ends_with($paramInfo['genericClass'], 'Collection')) {
            $result[] = 'int'; // Default key type
            $result[] = $this->findMostSpecificType($refinedTypes);
        } else {
            $result[] = $this->findMostSpecificType($refinedTypes);
        }

        // Pad with mixed if needed
        while (count($result) < count($paramInfo['templateTypes'])) {
            $result[] = 'mixed';
        }

        return $result;
    }

    private function normalizeType(string $type): string
    {
        // Remove null from union types
        $type = str_replace('|null', '', $type);
        $type = str_replace('null|', '', $type);

        // Handle fully qualified names
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
        $types = array_unique($types);

        if (count($types) === 1) {
            return $types[0];
        }

        $nonMixedTypes = array_filter($types, fn($type) => $type !== 'mixed');

        if (!empty($nonMixedTypes)) {
            return $nonMixedTypes[0];
        }

        return 'mixed';
    }
} 