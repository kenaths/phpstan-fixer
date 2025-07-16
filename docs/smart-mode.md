# Smart Mode - Multi-Pass Type Inference

The PHPStan Fixer now supports a **Smart Mode** that uses multi-pass analysis and type caching to significantly improve type inference accuracy when fixing interdependent classes.

## Overview

When fixing type errors across multiple files, the fixer may encounter situations where it cannot determine the exact type of a property or parameter because the relevant information is in another file that hasn't been processed yet. Smart Mode solves this by:

1. **Type Caching**: Storing discovered types in a persistent cache across runs
2. **Multi-Pass Processing**: Running the fixer multiple times to progressively improve type inference
3. **Cross-Class Analysis**: Using information from already-processed classes to infer types in dependent classes

## How It Works

### Single-Pass (Normal Mode)
```php
class A {
    public $data = ['apple', 'banana', 'cherry'];  // Gets 'array' type
}

class B {
    private $classA;
    
    public function __construct() {
        $this->classA = new A();
    }
    
    public function setData($data) {  // Gets 'mixed' type (no context)
        $this->classA->data = $data;
    }
}
```

### Multi-Pass (Smart Mode)
```php
// Pass 1: Fix class A
class A {
    public array $data = ['apple', 'banana', 'cherry'];  // Cache: A::$data = array<int, string>
}

// Pass 2: Use cached type from A to fix B
class B {
    private A $classA;
    
    public function __construct() {
        $this->classA = new A();
    }
    
    public function setData(array $data) {  // Uses cached type from A::$data
        $this->classA->data = $data;
    }
}
```

## Usage

### Command Line
```bash
# Enable smart mode with --smart flag
php phpstan-fixer fix src/ --level=1 --smart

# Or with other options
php phpstan-fixer fix src/ --level=1 --smart --dry-run
```

### Programmatic Usage
```php
use PHPStanFixer\PHPStanFixer;

$fixer = new PHPStanFixer();

// Enable smart mode with 5th parameter
$result = $fixer->fix(['src/'], 1, [], false, true);

// Check progress messages
foreach ($result->getMessages() as $message) {
    echo $message . "\n";
}
```

## Type Cache

Smart Mode maintains a persistent type cache in `.phpstan-fixer-cache.json` at your project root. This cache stores:

- **Property Types**: Class property types with both PHPDoc and native type information
- **Method Types**: Parameter types and return types for methods
- **File Timestamps**: To invalidate cache when files change

### Cache Structure
```json
{
  "version": "1.0",
  "cache": {
    "App\\Models\\User::$email": {
      "type": {
        "phpDoc": "string",
        "native": "string"
      },
      "timestamp": 1640995200,
      "file": "/path/to/User.php"
    },
    "App\\Models\\User::getName()": {
      "type": {
        "params": {
          "format": {
            "phpDoc": "string",
            "native": "string"
          }
        },
        "return": {
          "phpDoc": "string",
          "native": "string"
        }
      },
      "timestamp": 1640995200,
      "file": "/path/to/User.php"
    }
  },
  "generated_at": "2023-12-01 10:00:00"
}
```

## Multi-Pass Strategy

Smart Mode uses up to 3 passes:

1. **Pass 1 - Discovery**: Collect types from all files, avoid using 'mixed' where possible
2. **Pass 2 - Application**: Re-run using cached types from Pass 1
3. **Pass 3 - Fallback**: Final pass with 'mixed' types if still needed

The process stops early if:
- All errors are fixed
- No improvement is made between passes
- Maximum passes are reached

## Configuration

### Cache Location
The cache file is automatically created in your project root (detected by `composer.json` or `.git` directory).

### Cache Invalidation
The cache automatically invalidates entries when:
- Source files are modified (timestamp check)
- Files are deleted
- Cache format version changes

### Manual Cache Management
```bash
# Clear cache manually
rm .phpstan-fixer-cache.json

# Or programmatically
$cache = new TypeCache('/path/to/project');
$cache->clear();
```

## Best Practices

1. **Use with Version Control**: Add `.phpstan-fixer-cache.json` to your `.gitignore`
2. **CI/CD Integration**: Clear cache in CI environments for consistent results
3. **Large Codebases**: Smart mode is most beneficial for projects with many interdependent classes
4. **Iterative Development**: Run smart mode periodically during development to maintain type accuracy

## Performance Considerations

- **First Run**: Smart mode is slower due to multiple PHPStan analyses
- **Subsequent Runs**: Cached types speed up the process
- **Memory Usage**: Cache is memory-efficient and only loads relevant entries
- **Disk Space**: Cache files are typically small (< 1MB for most projects)

## Examples

### Constructor Promotion with Type Inference
```php
// Before (single pass)
class UserService {
    private $repository;
    private $validator;
    
    public function __construct($repository, $validator) {
        $this->repository = $repository;
        $this->validator = $validator;
    }
}

// After (smart mode)
class UserService {
    public function __construct(
        private UserRepository $repository,
        private UserValidator $validator
    ) {}
}
```

### Array Type Propagation
```php
// Before
class DataProcessor {
    public function process($items) {
        return array_map(fn($item) => $item->getName(), $items);
    }
}

// After (with cached User::getName(): string)
class DataProcessor {
    public function process(array $items): array {
        return array_map(fn($item) => $item->getName(), $items);
    }
}
```

### Generic Type Inference
```php
// Before
class Collection {
    private $items;
    
    public function add($item) {
        $this->items[] = $item;
    }
}

// After (with usage context)
class Collection {
    /** @var array<int, User> */
    private array $items;
    
    public function add(User $item): void {
        $this->items[] = $item;
    }
}
```

## Troubleshooting

### Cache Issues
- **Stale Types**: Delete cache file if types seem outdated
- **Permission Errors**: Ensure write permissions for project root
- **Corruption**: Cache auto-recovers from corruption by rebuilding

### Performance Issues
- **Long Analysis**: Use `--level=0` for initial runs on large codebases
- **Memory Limits**: Increase PHP memory limit for very large projects
- **Infinite Loops**: Built-in cycle detection prevents infinite loops

### Type Conflicts
- **Inconsistent Types**: Smart mode uses most specific type found
- **Union Types**: Automatically creates union types when multiple types are detected
- **Fallback to Mixed**: Uses 'mixed' when type cannot be determined

## Limitations

- **External Dependencies**: Cannot infer types from external libraries
- **Dynamic Properties**: Limited support for dynamically created properties
- **Reflection**: Cannot analyze types determined at runtime
- **Complex Generics**: Limited support for deeply nested generic types

## Migration Guide

### From Normal Mode
1. Run once with `--smart` flag to build initial cache
2. Review generated types for accuracy
3. Commit changes and integrate into workflow

### Upgrading Cache Format
- Cache automatically upgrades between versions
- Manual clearing may be needed for major version changes
- Backup important type annotations before upgrading