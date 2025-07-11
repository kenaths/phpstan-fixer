# PHPStan Auto-Fixer - Project Setup Guide

## Project Directory Structure

Create the following directory structure for the PHPStan Auto-Fixer library:

```
phpstan-fixer/
├── composer.json
├── README.md
├── phpstan.neon
├── .gitignore
├── LICENSE
├── bin/
│   └── phpstan-fix
├── src/
│   ├── PHPStanFixer.php
│   ├── FixResult.php
│   ├── Command/
│   │   └── FixCommand.php
│   ├── Contracts/
│   │   └── FixerInterface.php
│   ├── Fixers/
│   │   ├── AbstractFixer.php
│   │   ├── MissingReturnTypeFixer.php
│   │   ├── MissingParameterTypeFixer.php
│   │   ├── UndefinedVariableFixer.php
│   │   ├── UnusedVariableFixer.php
│   │   ├── StrictComparisonFixer.php
│   │   ├── NullCoalescingFixer.php
│   │   ├── MissingPropertyTypeFixer.php
│   │   ├── DocBlockFixer.php
│   │   └── Registry/
│   │       └── FixerRegistry.php
│   ├── Parser/
│   │   └── ErrorParser.php
│   ├── Runner/
│   │   └── PHPStanRunner.php
│   └── ValueObjects/
│       └── Error.php
├── tests/
│   ├── Fixers/
│   │   ├── MissingReturnTypeFixerTest.php
│   │   ├── MissingParameterTypeFixerTest.php
│   │   └── StrictComparisonFixerTest.php
│   └── PHPStanFixerIntegrationTest.php
└── examples/
    └── example-usage.php
```

## Step-by-Step Setup Instructions

### 1. Create the project directory
```bash
mkdir phpstan-fixer
cd phpstan-fixer
```

### 2. Create the directory structure
```bash
mkdir -p src/{Command,Contracts,Fixers/Registry,Parser,Runner,ValueObjects} tests/Fixers bin examples
```

### 3. Extract Files from Artifacts

You'll need to copy the content from each artifact into the appropriate files:

#### composer.json
Copy the content from the "composer.json - PHPStan Auto-Fixer Library" artifact.

#### src/PHPStanFixer.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract:
- The `PHPStanFixer` class (including namespace)
- The `FixResult` class → save as `src/FixResult.php`

#### src/ValueObjects/Error.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract the `Error` class.

#### src/Runner/PHPStanRunner.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract the `PHPStanRunner` class.

#### src/Parser/ErrorParser.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract the `ErrorParser` class.

#### src/Contracts/FixerInterface.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract the `FixerInterface` interface.

#### src/Fixers/Registry/FixerRegistry.php
From the "PHPStan Auto-Fixer - Main Library Code" artifact, extract the `FixerRegistry` class.

#### Individual Fixers
From the "PHPStan Auto-Fixer - Individual Fixers" artifact, extract each fixer class to its own file:
- `src/Fixers/AbstractFixer.php`
- `src/Fixers/MissingReturnTypeFixer.php`
- `src/Fixers/MissingParameterTypeFixer.php`
- `src/Fixers/UndefinedVariableFixer.php`
- `src/Fixers/UnusedVariableFixer.php`
- `src/Fixers/StrictComparisonFixer.php`
- `src/Fixers/NullCoalescingFixer.php`
- `src/Fixers/MissingPropertyTypeFixer.php`
- `src/Fixers/DocBlockFixer.php`

#### bin/phpstan-fix
From the "PHPStan Auto-Fixer - CLI Command" artifact:
1. Extract the CLI entry point script (the second part with `#!/usr/bin/env php`)
2. Save it as `bin/phpstan-fix`
3. Make it executable: `chmod +x bin/phpstan-fix`

#### src/Command/FixCommand.php
From the "PHPStan Auto-Fixer - CLI Command" artifact, extract the `FixCommand` class.

#### README.md
Copy the content from the "PHPStan Auto-Fixer - README and Usage Guide" artifact.

#### phpstan.neon
Copy the content from the "phpstan.neon - Example PHPStan Configuration" artifact.

#### tests/
From the "PHPStan Auto-Fixer - Unit Tests Example" artifact, extract the test classes to their respective files.

#### examples/example-usage.php
From the "PHPStan Auto-Fixer - Example Usage" artifact.

### 4. Create additional files

#### .gitignore
```gitignore
/vendor/
/composer.lock
/.phpunit.result.cache
/coverage/
*.bak
.DS_Store
.idea/
.vscode/
```

#### LICENSE (MIT)
```
MIT License

Copyright (c) 2024 PHPStan Auto-Fixer

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

### 5. Initialize the project
```bash
composer install
```

### 6. Test the installation
```bash
./bin/phpstan-fix --help
```

## Quick Git Setup

If you want to create a Git repository:

```bash
git init
git add .
git commit -m "Initial commit of PHPStan Auto-Fixer"

# If you have a GitHub/GitLab repository:
git remote add origin [your-repo-url]
git push -u origin main
```

## Publishing to Packagist

To make it installable via Composer:

1. Create a GitHub repository
2. Push your code
3. Go to https://packagist.org/
4. Submit your package
5. Set up GitHub webhook for auto-updates

## Development

To work on the library:

```bash
# Run tests
composer test

# Run PHPStan on the library itself
composer phpstan

# Test the CLI tool
./bin/phpstan-fix examples/ --dry-run
```
