<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers\Registry;

use PHPStanFixer\Contracts\FixerInterface;
use PHPStanFixer\ValueObjects\Error;

class FixerRegistry
{
    /** @var array<string, array<FixerInterface>> */
    private array $fixers = [];

    public function register(FixerInterface $fixer): void
    {
        foreach ($fixer->getSupportedTypes() as $type) {
            if (!isset($this->fixers[$type])) {
                $this->fixers[$type] = [];
            }
            $this->fixers[$type][] = $fixer;
        }
    }

    public function getFixerForError(Error $error): ?FixerInterface
    {
        $type = $error->getType();
        
        if (isset($this->fixers[$type])) {
            foreach ($this->fixers[$type] as $fixer) {
                if ($fixer->canFix($error)) {
                    return $fixer;
                }
            }
        }
        
        // Try all fixers if no specific type match
        foreach ($this->fixers as $fixerList) {
            foreach ($fixerList as $fixer) {
                if ($fixer->canFix($error)) {
                    return $fixer;
                }
            }
        }
        
        return null;
    }
}