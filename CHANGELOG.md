# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-11

### Added
- Initial release of PHPStan Auto-Fixer
- Support for PHP 8.2, 8.3, and 8.4
- Core fixers for common PHPStan errors:
  - Missing return types with union type support
  - Missing parameter types
  - Missing property types
  - Undefined variables
  - Unused variables
  - Strict comparisons (== to ===)
  - Null coalescing operator
  - PHPDoc fixes
- Missing iterable value types (PHPDoc annotations)
- Modern PHP 8+ feature support:
  - Union types (string|int)
  - Intersection types (Foo&Bar)
  - DNF types ((A&B)|C)
  - Readonly properties
  - Enums with backing types
  - Constructor property promotion
  - Never type
  - Mixed type with smart inference
  - Match expressions
  - First-class callables
- Command-line interface with options:
  - Dry-run mode
  - Custom PHPStan configuration
  - Backup file creation
  - Multiple path support
- Extensible architecture for custom fixers
- Comprehensive test suite
- Full PHPStan level 0-9 support

### Security
- Safe file handling with backup creation
- No execution of user code
- Proper error handling and validation