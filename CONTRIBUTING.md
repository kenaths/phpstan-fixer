# Contributing to PHPStan Auto-Fixer

Thank you for considering contributing to PHPStan Auto-Fixer! This document provides guidelines for contributing to the project.

## How to Contribute

### Reporting Issues

1. Check if the issue already exists
2. Include:
   - PHP version
   - PHPStan version
   - Minimal code example
   - Expected vs actual behavior
   - Full error message

### Submitting Pull Requests

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-new-fixer`
3. Make your changes
4. Add tests for new functionality
5. Ensure all tests pass: `composer test`
6. Run PHPStan: `vendor/bin/phpstan analyse src --level=5`
7. Commit with descriptive message
8. Push and create PR

### Creating New Fixers

1. Extend `AbstractFixer` class
2. Implement required methods:
   - `getSupportedTypes()`: Return error types
   - `canFix()`: Check if error is fixable
   - `fix()`: Apply the fix

Example:
```php
class MyCustomFixer extends AbstractFixer
{
    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        return ['my_error_type'];
    }

    public function canFix(Error $error): bool
    {
        return strpos($error->getMessage(), 'specific error') !== false;
    }

    public function fix(string $content, Error $error): string
    {
        $stmts = $this->parseCode($content);
        // Apply fixes...
        return $this->printCode($stmts);
    }
}
```

3. Add tests in `tests/Fixers/MyCustomFixerTest.php`
4. Register in `PHPStanFixer::registerDefaultFixers()`

### Code Style

- Follow PSR-12
- Use strict types: `declare(strict_types=1);`
- Add PHPDoc for complex logic
- Keep methods focused and small

### Testing

- Write unit tests for fixers
- Include edge cases
- Test with different PHP versions
- Ensure backward compatibility

### Documentation

- Update README for new features
- Add examples for new fixers
- Document breaking changes
- Update CHANGELOG.md

## Development Setup

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/phpstan-fixer.git
cd phpstan-fixer

# Install dependencies
composer install

# Run tests
composer test

# Run PHPStan
vendor/bin/phpstan analyse src
```

## Pull Request Process

1. Update documentation
2. Add tests
3. Update CHANGELOG.md
4. Ensure CI passes
5. Request review
6. Address feedback

## Code of Conduct

- Be respectful
- Welcome newcomers
- Focus on constructive feedback
- Assume good intentions

## Questions?

Open an issue for:
- Feature discussions
- Implementation questions
- Documentation clarifications

Thank you for contributing! 🎉