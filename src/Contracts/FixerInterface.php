<?php

declare(strict_types=1);

namespace PHPStanFixer\Contracts;

use PHPStanFixer\ValueObjects\Error;

interface FixerInterface
{
    /**
     * Check if this fixer can handle the given error
     */
    public function canFix(Error $error): bool;

    /**
     * Fix the error in the given content
     */
    public function fix(string $content, Error $error): string;

    /**
     * Get the error types this fixer handles
     * 
     * @return array<string>
     */
    public function getSupportedTypes(): array;
}