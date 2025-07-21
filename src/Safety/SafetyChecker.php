<?php

declare(strict_types=1);

namespace PHPStanFixer\Safety;

/**
 * Comprehensive safety checks to prevent critical errors in fixes
 */
class SafetyChecker
{
    /**
     * @return array<string> Array of safety violations
     */
    public function checkCode(string $originalContent, string $fixedContent): array
    {
        $violations = [];
        
        // 1. Syntax validation
        $syntaxViolations = $this->checkSyntax($fixedContent);
        $violations = array_merge($violations, $syntaxViolations);
        
        // 2. Critical structure preservation
        $structureViolations = $this->checkStructuralIntegrity($originalContent, $fixedContent);
        $violations = array_merge($violations, $structureViolations);
        
        // 3. Type safety validation
        $typeViolations = $this->checkTypeSafety($fixedContent);
        $violations = array_merge($violations, $typeViolations);
        
        // 4. Indentation consistency
        $indentationViolations = $this->checkIndentationConsistency($fixedContent);
        $violations = array_merge($violations, $indentationViolations);
        
        return $violations;
    }

    /**
     * @return array<string>
     */
    private function checkSyntax(string $content): array
    {
        try {
            $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
            $parser->parse($content);
            return [];
        } catch (\PhpParser\Error $e) {
            return ['Syntax error: ' . $e->getMessage()];
        }
    }

    /**
     * @return array<string>
     */
    private function checkStructuralIntegrity(string $originalContent, string $fixedContent): array
    {
        $violations = [];
        
        // Check class count preservation
        $originalClasses = $this->countClasses($originalContent);
        $fixedClasses = $this->countClasses($fixedContent);
        
        if ($originalClasses !== $fixedClasses) {
            $violations[] = sprintf(
                'Class count changed: %d -> %d', 
                $originalClasses, 
                $fixedClasses
            );
        }
        
        // Check method count preservation
        $originalMethods = $this->countMethods($originalContent);
        $fixedMethods = $this->countMethods($fixedContent);
        
        if ($originalMethods !== $fixedMethods) {
            $violations[] = sprintf(
                'Method count changed: %d -> %d', 
                $originalMethods, 
                $fixedMethods
            );
        }
        
        return $violations;
    }

    /**
     * @return array<string>
     */
    private function checkTypeSafety(string $content): array
    {
        $violations = [];
        
        // Check for dangerous type conversions
        if (preg_match_all('/\$\w+\s*=\s*\([^)]+\)\s*\$\w+/', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $violations[] = 'Potentially unsafe type cast detected: ' . trim($match);
            }
        }
        
        // Check for mixed assignments to typed properties
        if (preg_match_all('/(\w+)\s*:\s*(\w+)\s*=\s*null/', $content, $matches)) {
            $violations[] = 'Potential null assignment to non-nullable property';
        }
        
        return $violations;
    }

    /**
     * @return array<string>
     */
    private function checkIndentationConsistency(string $content): array
    {
        $violations = [];
        $lines = explode("\n", $content);
        
        $indentationStyle = null;
        $indentationLevel = 0;
        
        foreach ($lines as $lineNum => $line) {
            if (trim($line) === '') {
                continue; // Skip empty lines
            }
            
            // Detect indentation style
            if ($indentationStyle === null && preg_match('/^(\s+)/', $line, $matches)) {
                $indent = $matches[1];
                if (str_contains($indent, "\t")) {
                    $indentationStyle = 'tabs';
                } else {
                    $indentationStyle = 'spaces';
                }
            }
            
            // Check for mixed indentation
            if (preg_match('/^(\s+)/', $line, $matches)) {
                $indent = $matches[1];
                $hasTabs = str_contains($indent, "\t");
                $hasSpaces = str_contains($indent, " ");
                
                if ($hasTabs && $hasSpaces) {
                    $violations[] = sprintf(
                        'Mixed indentation on line %d', 
                        $lineNum + 1
                    );
                }
            }
            
            // Check for PHPDoc indentation consistency
            if (preg_match('/^(\s+)\/\*\*/', $line, $matches)) {
                $docIndent = $matches[1];
                if (preg_match('/^(\s+)\*/', $lines[$lineNum + 1] ?? '', $contentMatches)) {
                    $contentIndent = $contentMatches[1];
                    if (strlen($contentIndent) !== strlen($docIndent) + 1) {
                        $violations[] = sprintf(
                            'Inconsistent PHPDoc indentation starting at line %d', 
                            $lineNum + 1
                        );
                    }
                }
            }
        }
        
        return $violations;
    }

    private function countClasses(string $content): int
    {
        return preg_match_all('/\bclass\s+\w+/', $content);
    }

    private function countMethods(string $content): int
    {
        return preg_match_all('/\bfunction\s+\w+\s*\(/', $content);
    }

    /**
     * Check if a fix is safe to apply
     */
    public function isSafeToApply(string $originalContent, string $fixedContent): bool
    {
        $violations = $this->checkCode($originalContent, $fixedContent);
        
        // Filter out non-critical violations
        $criticalViolations = array_filter($violations, function($violation) {
            return str_contains($violation, 'Syntax error') ||
                   str_contains($violation, 'Class count changed') ||
                   str_contains($violation, 'Method count changed');
        });
        
        return empty($criticalViolations);
    }
}