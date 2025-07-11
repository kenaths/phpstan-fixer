<?php

// Example: Before and After PHPStan Auto-Fixer

// ===== BEFORE: Code with PHPStan errors =====

class UserService
{
    private $repository;
    
    // Missing return type (Level 1+ error)
    public function getUser($id)
    {
        // Undefined variable (Level 0 error)
        return $user ?? $this->repository->find($id);
    }
    
    // Missing parameter type (Level 1+ error)
    public function updateUser($id, array $data)
    {
        $user = $this->repository->find($id);
        
        // Non-strict comparison (Level 4+ error)
        if ($user == null) {
            return null;
        }
        
        // Unused variable (Level 0 error)
        $timestamp = time();
        
        foreach ($data as $key => $value) {
            $user->$key = $value;
        }
        
        return $this->repository->save($user);
    }
    
    // Missing property type and return type
    private $cache;
    
    public function getCachedUser($id)
    {
        // Can be converted to null coalescing
        return isset($this->cache[$id]) ? $this->cache[$id] : null;
    }
}

// ===== AFTER: Code fixed by PHPStan Auto-Fixer =====

class UserServiceFixed
{
    private mixed $repository;
    
    // Fixed: Added return type
    public function getUser(mixed $id): mixed
    {
        // Fixed: Initialized undefined variable
        $user = null;
        return $user ?? $this->repository->find($id);
    }
    
    // Fixed: Added parameter type
    public function updateUser(mixed $id, array $data): mixed
    {
        $user = $this->repository->find($id);
        
        // Fixed: Strict comparison
        if ($user === null) {
            return null;
        }
        
        // Fixed: Removed unused variable assignment
        // $timestamp = time();
        
        foreach ($data as $key => $value) {
            $user->$key = $value;
        }
        
        return $this->repository->save($user);
    }
    
    // Fixed: Added property type
    private mixed $cache;
    
    // Fixed: Added return type
    public function getCachedUser(mixed $id): mixed
    {
        // Fixed: Converted to null coalescing operator
        return $this->cache[$id] ?? null;
    }
}

// ===== Example of running the fixer programmatically =====

use PHPStanFixer\PHPStanFixer;

// Create fixer instance
$fixer = new PHPStanFixer();

// Fix errors at level 5
$result = $fixer->fix(['src/UserService.php'], 5);

// Display results
echo "PHPStan Auto-Fixer Results:\n";
echo "==========================\n\n";

echo "Fixed {$result->getFixedCount()} errors:\n";
foreach ($result->getFixedErrors() as $error) {
    echo "  ✓ Line {$error->getLine()}: {$error->getMessage()}\n";
}

echo "\nCould not fix {$result->getUnfixableCount()} errors:\n";
foreach ($result->getUnfixableErrors() as $error) {
    echo "  ✗ Line {$error->getLine()}: {$error->getMessage()}\n";
}

echo "\nBackup files created:\n";
foreach ($result->getFixedFiles() as $file => $backup) {
    echo "  {$file} → {$backup}\n";
}

/* Example output:

PHPStan Auto-Fixer Results:
==========================

Fixed 7 errors:
  ✓ Line 8: Method UserService::getUser() has no return type specified.
  ✓ Line 11: Undefined variable: $user
  ✓ Line 15: Parameter $id of method UserService::updateUser() has no type specified.
  ✓ Line 20: Strict comparison using === between $user and null will always evaluate to false.
  ✓ Line 25: Variable $timestamp is never used.
  ✓ Line 35: Property UserService::$cache has no type specified.
  ✓ Line 40: isset() construct can be replaced with null coalesce operator

Could not fix 0 errors:

Backup files created:
  src/UserService.php → src/UserService.php.phpstan-fixer.bak

*/