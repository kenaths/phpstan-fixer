# Publishing PHPStan Auto-Fixer to Packagist

This guide provides step-by-step instructions for publishing this package to Packagist.

## Pre-Publishing Checklist

### 1. Code Quality ✓
- [x] All tests pass (17/17 tests passing)
- [x] PHPStan analysis completed (48 minor errors remaining, mostly type certainty)
- [x] PHP 8.2, 8.3, and 8.4 compatibility verified
- [x] Code follows PSR-12 standards

### 2. Documentation ✓
- [x] README.md is comprehensive with examples
- [x] LICENSE file (MIT) is present
- [x] CHANGELOG.md tracks changes
- [x] Code is well-commented
- [x] Installation and usage instructions are clear

### 3. Package Configuration ✓
- [x] composer.json is properly configured
- [x] Autoloading is set up correctly (PSR-4)
- [x] Dependencies are appropriate
- [x] Binary is properly configured (bin/phpstan-fix)

## Publishing Steps

### Step 1: Create GitHub Repository

1. Create a new repository on GitHub:
   ```bash
   # Initialize git if not already done
   git init
   
   # Add all files
   git add .
   
   # Create initial commit
   git commit -m "Initial release of PHPStan Auto-Fixer v1.0.0"
   
   # Add remote origin (replace with your repository URL)
   git remote add origin https://github.com/YOUR_USERNAME/phpstan-fixer.git
   
   # Push to GitHub
   git push -u origin main
   ```

2. Ensure the repository is public

### Step 2: Create Release Tag

```bash
# Create a version tag
git tag -a v1.0.0 -m "Release version 1.0.0"

# Push the tag
git push origin v1.0.0
```

### Step 3: Update composer.json (if needed)

Ensure your composer.json has all required fields:

```json
{
    "name": "YOUR_VENDOR/phpstan-fixer",
    "description": "Automatically fix PHPStan errors in your PHP code",
    "keywords": ["phpstan", "static-analysis", "code-fixer", "php8", "automation"],
    "homepage": "https://github.com/YOUR_USERNAME/phpstan-fixer",
    "license": "MIT",
    "authors": [
        {
            "name": "Your Name",
            "email": "your.email@example.com"
        }
    ],
    "support": {
        "issues": "https://github.com/YOUR_USERNAME/phpstan-fixer/issues",
        "source": "https://github.com/YOUR_USERNAME/phpstan-fixer"
    }
}
```

### Step 4: Submit to Packagist

1. Go to [https://packagist.org/](https://packagist.org/)
2. Login or create an account
3. Click "Submit" in the top menu
4. Enter your GitHub repository URL
5. Click "Check" to validate
6. If validation passes, click "Submit"

### Step 5: Set Up Auto-Update (Recommended)

1. In your Packagist package page, go to "Settings"
2. Click "Update" webhook
3. Copy the webhook URL
4. In your GitHub repository:
   - Go to Settings > Webhooks
   - Click "Add webhook"
   - Paste the Packagist webhook URL
   - Set Content type to "application/json"
   - Select "Just the push event"
   - Save the webhook

### Step 6: Add Badges to README

Add these badges to your README.md:

```markdown
[![Latest Stable Version](https://poser.pugx.org/YOUR_VENDOR/phpstan-fixer/v/stable)](https://packagist.org/packages/YOUR_VENDOR/phpstan-fixer)
[![Total Downloads](https://poser.pugx.org/YOUR_VENDOR/phpstan-fixer/downloads)](https://packagist.org/packages/YOUR_VENDOR/phpstan-fixer)
[![License](https://poser.pugx.org/YOUR_VENDOR/phpstan-fixer/license)](https://packagist.org/packages/YOUR_VENDOR/phpstan-fixer)
[![PHP Version Require](https://poser.pugx.org/YOUR_VENDOR/phpstan-fixer/require/php)](https://packagist.org/packages/YOUR_VENDOR/phpstan-fixer)
```

## Post-Publishing Tasks

### 1. Test Installation
```bash
# Create a test project
mkdir test-phpstan-fixer
cd test-phpstan-fixer
composer init --no-interaction

# Install your package
composer require --dev YOUR_VENDOR/phpstan-fixer

# Test the binary
vendor/bin/phpstan-fix --help
```

### 2. Announce the Release
- Write a blog post or article
- Share on Twitter/X with #PHP #PHPStan tags
- Post in relevant PHP communities (Reddit r/PHP, PHP Discord, etc.)
- Submit to PHP Weekly newsletter

### 3. Monitor and Maintain
- Watch for issues on GitHub
- Respond to user feedback
- Plan future releases
- Keep dependencies updated

## Version Management

Follow Semantic Versioning:
- MAJOR version: Breaking changes
- MINOR version: New features (backward compatible)
- PATCH version: Bug fixes

Example:
- 1.0.0 → 1.0.1 (bug fix)
- 1.0.1 → 1.1.0 (new fixer added)
- 1.1.0 → 2.0.0 (breaking API change)

## Continuous Integration (Optional but Recommended)

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.2', '8.3', '8.4']
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php-version }}
        coverage: none
    
    - name: Install dependencies
      run: composer install --prefer-dist --no-progress
    
    - name: Run tests
      run: composer test
    
    - name: Run PHPStan
      run: vendor/bin/phpstan analyse src --level=5
```

## Important Notes

1. **Vendor Name**: Replace `YOUR_VENDOR` with your actual vendor name (usually your username or organization name)
2. **Package Name**: The package name should be lowercase and use hyphens (not underscores)
3. **Stability**: Mark as stable only when thoroughly tested
4. **Security**: Never commit sensitive information (passwords, API keys)
5. **Licensing**: Ensure all code is properly licensed

## Troubleshooting

### Package Not Found After Publishing
- Wait 1-2 minutes for Packagist to process
- Check if the repository is public
- Verify composer.json is valid JSON

### Webhook Not Working
- Ensure the webhook URL is correct
- Check GitHub webhook delivery history
- Verify Packagist API token if using authentication

## Success Metrics

Track your package success:
- Downloads on Packagist
- GitHub stars and forks
- Issues and pull requests
- Community feedback

Good luck with your package release! 🚀