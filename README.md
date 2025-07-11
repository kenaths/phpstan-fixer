# PHPStan Auto-Fixer

A powerful tool that automatically fixes PHPStan errors in your PHP code. Now with full **PHP 8.4 support**! 🚀

## Features

- ✅ Automatically fixes common PHPStan errors
- ✅ Full support for PHP 8.2, 8.3, and 8.4 features
- ✅ Supports all PHPStan levels (0-9)
- ✅ Creates backup files before making changes
- ✅ Dry-run mode to preview fixes
- ✅ Extensible architecture for custom fixers
- ✅ Modern PHP type system support (union, intersection, DNF types)
- ✅ Smart type inference
- ✅ Command-line interface

## Installation

Install via Composer:

```bash
composer require --dev phpstan-fixer/phpstan-fixer
```

## Usage

### Command Line

Fix PHPStan errors in your source directory:

```bash
vendor/bin/phpstan-fix src/
```

Specify PHPStan level:

```bash
vendor/bin/phpstan-fix src/ --level=5
```

Preview what would be fixed without making changes:

```bash
vendor/bin/phpstan-fix src/ --dry-run
```

Use a custom PHPStan configuration:

```bash
vendor/bin/phpstan-fix src/ --config=phpstan.neon
```

### Programmatic Usage

```php
use PHPStanFixer\PHPStanFixer;

$fixer = new PHPStanFixer();
$result = $fixer->fix(['src/', 'tests/'], 5);

echo "Fixed: " . $result->getFixedCount() . " errors\n";
echo "Unfixable: " . $result->getUnfixableCount() . " errors\n";

foreach ($result->getFixedErrors() as $error) {
    echo "Fixed: {$error->getFile()}:{$error->getLine()} - {$error->getMessage()}\n";
}
```

## Supported Fixes

### Core Fixes

1. **Missing Return Types** - Automatically infers and adds return types with union type support
2. **Missing Parameter Types** - Adds type declarations to method parameters
3. **Missing Property Types** - Adds type declarations to class properties
4. **Undefined Variables** - Initializes undefined variables
5. **Unused Variables** - Removes unused variable assignments
6. **Strict Comparisons** - Converts `==` to `===` and `!=` to `!==`
7. **Null Coalescing** - Converts `isset() ?:` to `??` operator
8. **PHPDoc Fixes** - Fixes invalid PHPDoc tags
9. **Missing Iterable Value Types** - Adds type hints for array/iterable values

### PHP 8+ Modern Features

10. **Union Types** - Handles `string|int` and complex union types
11. **Intersection Types** - Supports `Foo&Bar` type declarations
12. **DNF Types** - Disjunctive Normal Form types like `(A&B)|C`
13. **Readonly Properties** - Adds readonly modifier and ensures types
14. **Enums** - Fixes enum backing types and cases
15. **Constructor Property Promotion** - Converts traditional properties to promoted ones
16. **Never Type** - Properly handles `never` return type
17. **Mixed Type** - Smart inference of `mixed` type
18. **Match Expressions** - Type inference for match expressions
19. **First-class Callables** - Proper handling of callable syntax

## Creating Custom Fixers

You can create custom fixers for specific error types:

```php
use PHPStanFixer\Fixers\AbstractFixer;
use PHPStanFixer\ValueObjects\Error;

class MyCustomFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['my_error_type'];
    }

    public function canFix(Error $error): bool
    {
        return strpos($error->getMessage(), 'My specific error') !== false;
    }

    public function fix(string $content, Error $error): string
    {
        // Implement your fix logic here
        // Use PHP-Parser to modify the AST
        $stmts = $this->parseCode($content);

        // Modify the AST...

        return $this->printCode($stmts);
    }
}

// Register the fixer
$fixer = new PHPStanFixer();
$fixer->registerFixer(new MyCustomFixer());
```

## Configuration

Create a `phpstan.neon` file in your project root:

```neon
parameters:
    level: 5
    paths:
        - src
        - tests
    excludePaths:
        - tests/fixtures/*

includes:
    - phpstan-baseline.neon
```

## Limitations

Not all PHPStan errors can be automatically fixed. Some errors require human intervention to understand the intent of the code. The library will report which errors it couldn't fix.

Common unfixable errors include:

- Complex type mismatches
- Logic errors
- Missing dependencies
- Errors requiring architectural changes

## Safety

- The tool creates `.bak` backup files before modifying any source files
- Use `--dry-run` to preview changes before applying them
- Always review the changes and test your code after running the fixer
- Use version control to track changes

## Contributing

Contributions are welcome! To add support for new error types:

1. Create a new fixer class extending `AbstractFixer`
2. Implement the required methods
3. Add tests for your fixer
4. Submit a pull request

## License

This library is open-source software licensed under the MIT license.

## Tips

1. **Start with lower levels**: Begin with level 0 and work your way up
2. **Use version control**: Always commit your code before running the fixer
3. **Review changes**: Automated fixes may not always match your intent
4. **Combine with CI**: Run PHPStan in your CI pipeline after fixing
5. **Custom fixers**: Create fixers for your project-specific patterns

## Troubleshooting

### "PHPStan executable not found"

Make sure PHPStan is installed:

```bash
composer require --dev phpstan/phpstan
```

### "Autoloader not found"

Run composer install:

```bash
composer install
```

### Fixes seem incorrect

1. Check if you're using the correct PHPStan level
2. Review the specific error message
3. Consider creating a custom fixer for your use case
4. Some fixes are generic and may need manual adjustment

## Modern PHP Examples

### Before Fix

```php
class UserService
{
    private $repository;

    public function findUser($id)
    {
        if ($id == null) {
            return null;
        }

        $unused = "This will be removed";

        return $this->repository->find($id);
    }
}
```

### After Fix

```php
class UserService
{
    private mixed $repository;

    public function findUser(mixed $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->repository->find($id);
    }
}
```

### Constructor Promotion Example

```php
// Before
class Product
{
    private string $name;
    private float $price;

    public function __construct(string $name, float $price)
    {
        $this->name = $name;
        $this->price = $price;
    }
}

// After (with constructor promotion)
class Product
{
    public function __construct(
        private string $name,
        private float $price
    ) {
    }
}
```

## Example Workflow

```bash
# 1. Analyze your code first
vendor/bin/phpstan analyse --level=9

# 2. Preview what can be fixed
vendor/bin/phpstan-fix src/ --level=9 --dry-run

# 3. Apply fixes
vendor/bin/phpstan-fix src/ --level=9

# 4. Run PHPStan again to verify
vendor/bin/phpstan analyse --level=9

# 5. Review and test changes
git diff
composer test
```
