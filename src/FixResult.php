<?php

declare(strict_types=1);

namespace PHPStanFixer;

use PHPStanFixer\ValueObjects\Error;

class FixResult
{
    /** @var array<Error> */
    private array $fixedErrors = [];
    /** @var array<Error> */
    private array $unfixableErrors = [];
    /** @var array<string, string|null> */
    private array $fixedFiles = [];
    /** @var array<string> */
    private array $errors = [];
    private string $message = '';
    /** @var array<string> */
    private array $messages = [];

    public function addFixedError(Error $error): void
    {
        $this->fixedErrors[] = $error;
    }

    public function addUnfixableError(Error $error): void
    {
        $this->unfixableErrors[] = $error;
    }

    public function addFixedFile(string $file, ?string $backupFile): void
    {
        $this->fixedFiles[$file] = $backupFile;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<Error>
     */
    public function getFixedErrors(): array
    {
        return $this->fixedErrors;
    }

    /**
     * @return array<Error>
     */
    public function getUnfixableErrors(): array
    {
        return $this->unfixableErrors;
    }

    /**
     * @return array<string, string|null>
     */
    public function getFixedFiles(): array
    {
        return $this->fixedFiles;
    }

    /**
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getFixedCount(): int
    {
        return count($this->fixedErrors);
    }

    public function getUnfixableCount(): int
    {
        return count($this->unfixableErrors);
    }
}