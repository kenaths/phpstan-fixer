# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-01-21

### 🚀 Major Enhancements

#### ✨ Enhanced Type Safety & Indentation
- **FIXED**: Perfect PHPDoc indentation handling - maintains exact 4-space alignment
- **NEW**: Advanced Type Consistency Fixer for complex property/method type mismatches
- **ENHANCED**: Smart array type inference with context-aware analysis
- **IMPROVED**: Comprehensive error handling with graceful fallbacks

#### 🎯 Critical Bug Fixes
- **FIXED**: PHPDoc comments showing 8 spaces instead of 4 (line-start position calculation)
- **FIXED**: Type mismatch errors like `?Closure` property with `string` assignment
- **FIXED**: AST node position issues causing indentation accumulation
- **FIXED**: Property type inconsistencies with automatic union type creation

#### 🛡️ Production-Ready Safety
- **NEW**: Comprehensive input validation and edge case handling
- **NEW**: Atomic fix application with automatic rollback on errors
- **NEW**: Detailed error logging for debugging and monitoring
- **NEW**: Safety validation prevents breaking code changes

#### 🧠 Smart Mode Enhancements
- **ENHANCED**: Multi-pass analysis for complex codebases
- **NEW**: Intelligent caching system for faster subsequent runs
- **IMPROVED**: Better error message parsing with multiple pattern matching
- **ENHANCED**: Position-aware AST traversal and fix application

### 📦 Technical Improvements
- **ENHANCED**: `MissingIterableValueTypeFixer` with proper line-start calculation
- **NEW**: `TypeConsistencyFixer` for resolving property/method type conflicts
- **ENHANCED**: `IndentationHelper` with comprehensive error handling
- **IMPROVED**: All fixers now include extensive documentation and error handling

### 🔧 Developer Experience
- **IMPROVED**: Enhanced error messages with specific fix locations
- **NEW**: Comprehensive logging for production debugging
- **ENHANCED**: Better validation prevents invalid fix applications
- **IMPROVED**: Documentation with detailed technical explanations

## [1.1.0] - 2025-01-11

### Changed
- Added support for PHPStan v2.0 and higher
- Updated all dependencies to latest versions:
  - PHPStan ^1.11|^2.0
  - PHP-Parser v5.5
  - Symfony components v7.3
  - PHPUnit v11.5
- Fixed compatibility issues with PHP-Parser v5
- Updated test assertions for new formatting

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