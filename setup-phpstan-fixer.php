#!/usr/bin/env php
<?php

/**
 * PHPStan Auto-Fixer Setup Script
 * 
 * This script creates the complete project structure for the PHPStan Auto-Fixer library.
 * Save this file as 'setup-phpstan-fixer.php' and run it with: php setup-phpstan-fixer.php
 */

$projectName = 'phpstan-fixer';
$baseDir = __DIR__;

// Create base directory
// if (!mkdir($baseDir, 0755, true)) {
//     die("Failed to create directory: $baseDir\n");
// }

echo "Creating PHPStan Auto-Fixer project structure...\n";

// Create directory structure
$directories = [
    'bin',
    'src/Command',
    'src/Contracts',
    'src/Fixers/Registry',
    'src/Parser',
    'src/Runner',
    'src/ValueObjects',
    'tests/Fixers',
    'examples'
];

foreach ($directories as $dir) {
    $path = $baseDir . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "Created directory: $dir\n";
    }
}

// File contents - You'll need to paste the actual content from the artifacts
$files = [
    'composer.json' => <<<'JSON'
{
    "name": "phpstan-fixer/phpstan-fixer",
    "description": "A library to automatically fix PHPStan errors based on the provided level",
    "type": "library",
    "license": "MIT",
    "authors": [
        {
            "name": "PHPStan Fixer",
            "email": "info@phpstan-fixer.com"
        }
    ],
    "require": {
        "php": "^8.0",
        "phpstan/phpstan": "^1.10",
        "nikic/php-parser": "^4.15",
        "symfony/console": "^6.0",
        "symfony/process": "^6.0",
        "symfony/filesystem": "^6.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "PHPStanFixer\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "PHPStanFixer\\Tests\\": "tests/"
        }
    },
    "bin": [
        "bin/phpstan-fix"
    ],
    "scripts": {
        "test": "vendor/bin/phpunit",
        "phpstan": "vendor/bin/phpstan analyse"
    }
}
JSON,

    '.gitignore' => <<<'GITIGNORE'
/vendor/
/composer.lock
/.phpunit.result.cache
/coverage/
*.bak
.DS_Store
.idea/
.vscode/
GITIGNORE,

    'LICENSE' => <<<'LICENSE'
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
LICENSE,

    'README.md' => <<<'README'
# PHPStan Auto-Fixer

A PHP library that automatically fixes PHPStan errors based on the specified analysis level.

## Installation

```bash
composer require --dev phpstan-fixer/phpstan-fixer
```

## Quick Start

```bash
# Fix PHPStan errors in your source directory
vendor/bin/phpstan-fix src/

# Specify PHPStan level
vendor/bin/phpstan-fix src/ --level=5

# Preview changes without applying
vendor/bin/phpstan-fix src/ --dry-run
```

## Note

This is a skeleton setup. You need to copy the actual implementation code from the artifacts into the respective files.

See the full documentation for more details.
README
];

// Create files
foreach ($files as $filename => $content) {
    $filepath = $baseDir . '/' . $filename;
    file_put_contents($filepath, $content);
    echo "Created file: $filename\n";
}

// Create placeholder files for the actual implementation
$placeholderFiles = [
    'bin/phpstan-fix' => "#!/usr/bin/env php\n<?php\n// Copy the actual CLI script content from the artifact here\n",
    'src/PHPStanFixer.php' => "<?php\n// Copy the PHPStanFixer class from the main library artifact\n",
    'src/FixResult.php' => "<?php\n// Copy the FixResult class from the main library artifact\n",
    'src/Command/FixCommand.php' => "<?php\n// Copy the FixCommand class from the CLI artifact\n",
    'src/Contracts/FixerInterface.php' => "<?php\n// Copy the FixerInterface from the main library artifact\n",
    'src/Fixers/AbstractFixer.php' => "<?php\n// Copy the AbstractFixer class from the fixers artifact\n",
    'src/Fixers/MissingReturnTypeFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/MissingParameterTypeFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/UndefinedVariableFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/UnusedVariableFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/StrictComparisonFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/NullCoalescingFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/MissingPropertyTypeFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/DocBlockFixer.php' => "<?php\n// Copy from the fixers artifact\n",
    'src/Fixers/Registry/FixerRegistry.php' => "<?php\n// Copy from the main library artifact\n",
    'src/Parser/ErrorParser.php' => "<?php\n// Copy from the main library artifact\n",
    'src/Runner/PHPStanRunner.php' => "<?php\n// Copy from the main library artifact\n",
    'src/ValueObjects/Error.php' => "<?php\n// Copy from the main library artifact\n",
    'phpstan.neon' => "# Copy the PHPStan configuration from the artifact\n",
    'examples/example-usage.php' => "<?php\n// Copy the example usage from the artifact\n",
];

foreach ($placeholderFiles as $filename => $content) {
    $filepath = $baseDir . '/' . $filename;
    file_put_contents($filepath, $content);
    echo "Created placeholder: $filename\n";
}

// Make bin/phpstan-fix executable
chmod($baseDir . '/bin/phpstan-fix', 0755);

echo "\n✅ Project structure created successfully!\n";
echo "\nNext steps:\n";
echo "1. cd $projectName\n";
echo "2. Copy the actual code from each artifact into the corresponding files\n";
echo "3. Run 'composer install' to install dependencies\n";
echo "4. Test with './bin/phpstan-fix --help'\n";
echo "\nTo create a git repository:\n";
echo "   git init\n";
echo "   git add .\n";
echo "   git commit -m 'Initial commit'\n";

// Optionally create a helper script to show which content goes where
$mappingGuide = <<<'GUIDE'
# PHPStan Auto-Fixer - File Mapping Guide

## From "PHPStan Auto-Fixer - Main Library Code" artifact:

1. Extract the PHPStanFixer class → src/PHPStanFixer.php
2. Extract the FixResult class → src/FixResult.php
3. Extract the Error class (namespace PHPStanFixer\ValueObjects) → src/ValueObjects/Error.php
4. Extract the PHPStanRunner class → src/Runner/PHPStanRunner.php
5. Extract the ErrorParser class → src/Parser/ErrorParser.php
6. Extract the FixerInterface → src/Contracts/FixerInterface.php
7. Extract the FixerRegistry class → src/Fixers/Registry/FixerRegistry.php

## From "PHPStan Auto-Fixer - Individual Fixers" artifact:

1. Extract AbstractFixer → src/Fixers/AbstractFixer.php
2. Extract MissingReturnTypeFixer → src/Fixers/MissingReturnTypeFixer.php
3. Extract MissingParameterTypeFixer → src/Fixers/MissingParameterTypeFixer.php
4. Extract UndefinedVariableFixer → src/Fixers/UndefinedVariableFixer.php
5. Extract UnusedVariableFixer → src/Fixers/UnusedVariableFixer.php
6. Extract StrictComparisonFixer → src/Fixers/StrictComparisonFixer.php
7. Extract NullCoalescingFixer → src/Fixers/NullCoalescingFixer.php
8. Extract MissingPropertyTypeFixer → src/Fixers/MissingPropertyTypeFixer.php
9. Extract DocBlockFixer → src/Fixers/DocBlockFixer.php

## From "PHPStan Auto-Fixer - CLI Command" artifact:

1. Extract the FixCommand class → src/Command/FixCommand.php
2. Extract the CLI entry point (second part with #!/usr/bin/env php) → bin/phpstan-fix

## Other artifacts:

- "PHPStan Auto-Fixer - README and Usage Guide" → README.md
- "phpstan.neon - Example PHPStan Configuration" → phpstan.neon
- "PHPStan Auto-Fixer - Example Usage" → examples/example-usage.php
- "PHPStan Auto-Fixer - Unit Tests Example" → Split into appropriate test files

GUIDE;

file_put_contents($baseDir . '/FILE_MAPPING_GUIDE.md', $mappingGuide);
echo "\n📋 Created FILE_MAPPING_GUIDE.md to help you map artifact content to files\n";