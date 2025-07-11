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
