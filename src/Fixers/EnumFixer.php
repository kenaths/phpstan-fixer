<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStanFixer\ValueObjects\Error;

/**
 * Fixes enum-related errors in PHP 8.1+
 */
class EnumFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return [
            'enum_case_mismatch',
            'enum_missing_case',
            'invalid_enum_backing_type',
        ];
    }

    public function canFix(Error $error): bool
    {
        return (bool) preg_match('/Enum case|Enum .* must|Enum backing type/', $error->getMessage());
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        if ($stmts === null) {
            return $content;
        }

        $visitor = new class($error->getLine(), $error->getMessage()) extends NodeVisitorAbstract {
            private int $targetLine;
            private string $message;

            public function __construct(int $targetLine, string $message)
            {
                $this->targetLine = $targetLine;
                $this->message = $message;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Enum_ && abs($node->getLine() - $this->targetLine) < 10) {
                    // Fix enum backing type
                    if (preg_match('/Enum backing type must be (int|string)/', $this->message, $matches)) {
                        $backingType = $matches[1];
                        $node->scalarType = new Node\Identifier($backingType);
                    }

                    // Add missing enum cases
                    if (str_contains($this->message, 'must implement all cases')) {
                        $this->addMissingEnumCases($node);
                    }
                }

                // Fix enum case usage
                if ($node instanceof Node\Expr\ClassConstFetch 
                    && str_contains($this->message, 'Enum case') 
                    && str_contains($this->message, 'does not exist')) {
                    
                    preg_match('/Enum case (.*?)::(\w+) does not exist/', $this->message, $matches);
                    if (isset($matches[2])) {
                        $caseName = $matches[2];
                        // Try to fix typos in case names
                        $node->name = new Node\Identifier($this->findSimilarCase($caseName));
                    }
                }

                return null;
            }

            private function addMissingEnumCases(Node\Stmt\Enum_ $enum): void
            {
                // This is a simplified example
                // In practice, you'd need to analyze the enum usage to determine missing cases
                $existingCases = [];
                foreach ($enum->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\EnumCase) {
                        $existingCases[] = $stmt->name->toString();
                    }
                }

                // Add common missing cases based on enum name
                $enumName = $enum->name->toString();
                if (str_contains(strtolower($enumName), 'status')) {
                    $commonCases = ['PENDING', 'ACTIVE', 'COMPLETED', 'FAILED'];
                    foreach ($commonCases as $case) {
                        if (!in_array($case, $existingCases)) {
                            $enum->stmts[] = new Node\Stmt\EnumCase(
                                new Node\Identifier($case),
                                $enum->scalarType ? new Node\Scalar\String_(strtolower($case)) : null
                            );
                        }
                    }
                }
            }

            private function findSimilarCase(string $caseName): string
            {
                // Simple typo correction
                $commonTypos = [
                    'ACTIV' => 'ACTIVE',
                    'COMPLET' => 'COMPLETED',
                    'FAILD' => 'FAILED',
                    'PEDING' => 'PENDING',
                ];

                return $commonTypos[$caseName] ?? $caseName;
            }
        };

        $stmts = $this->traverseWithVisitor($stmts, $visitor);
        return $this->printCode($stmts);
    }
}