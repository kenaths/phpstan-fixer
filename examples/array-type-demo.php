<?php

/**
 * Demo of improved array type inference in PHPStan Fixer
 * 
 * This file demonstrates how the fixer now infers specific array types
 * instead of just using array<mixed>
 */

class ArrayTypeDemo
{
    // Before: @var array<mixed>
    // After:  @var array<int>
    private array $numbers = [1, 2, 3, 4, 5];

    // Before: @var array<mixed>  
    // After:  @var array<string>
    private array $names = ['John', 'Jane', 'Bob', 'Alice'];

    // Before: @var array<mixed>
    // After:  @var array<string, string>  
    private array $config = [
        'host' => 'localhost',
        'port' => '3306',
        'username' => 'root',
        'database' => 'myapp'
    ];

    // Before: @var array<mixed>
    // After:  @var array<string, string|int|bool>
    private array $userProfile = [
        'name' => 'John Doe',
        'age' => 30,
        'email' => 'john@example.com',
        'active' => true,
        'score' => 95
    ];

    // Before: @return array<mixed>
    // After:  @return array<string, int>
    public function getScores(): array
    {
        return [
            'math' => 95,
            'science' => 88,
            'english' => 92,
            'history' => 87
        ];
    }

    // Before: @return array<mixed>
    // After:  @return array<int, string>
    public function getDaysOfWeek(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday', 
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday'
        ];
    }

    // Before: @param array<mixed> $items
    // After:  @param array<string> $items
    public function processItems(array $items): void
    {
        // When PHPStan detects the parameter has no value type,
        // the fixer will analyze how it's used to infer the type
    }

    // Mixed arrays still use array<mixed> when types can't be determined
    private array $unknown;
}