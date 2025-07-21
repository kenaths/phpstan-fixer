<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Parser;

use PHPStanFixer\Parser\ErrorParser;
use PHPUnit\Framework\TestCase;

class ErrorParserIgnoredPatternTest extends TestCase
{
    private ErrorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ErrorParser();
    }

    public function testIgnoredPatternWarningsAreFiltered(): void
    {
        $phpstanOutput = json_encode([
            'totals' => ['errors' => 4, 'file_errors' => 1],
            'files' => [
                '/path/to/file.php' => [
                    'errors' => 1,
                    'messages' => [
                        [
                            'message' => 'Access to an undefined property Test::$property.',
                            'line' => 25,
                            'identifier' => 'property.notFound'
                        ]
                    ]
                ]
            ],
            'errors' => [
                'Ignored error pattern #Trait .* is used zero times and is not analysed# was not matched in reported errors.',
                'Ignored error pattern #Unsafe usage of new static\\(\\)# was not matched in reported errors.',
                'Ignored error pattern #Variable \\$namespaceParts in empty\\(\\) always exists and is not falsy# was not matched in reported errors.'
            ]
        ]);

        $errors = $this->parser->parse($phpstanOutput);

        // Should only have one error (the actual error, not the ignored pattern warnings)
        $this->assertCount(1, $errors);
        $this->assertEquals('Access to an undefined property Test::$property.', $errors[0]->getMessage());
        $this->assertEquals('/path/to/file.php', $errors[0]->getFile());
        $this->assertEquals(25, $errors[0]->getLine());
    }

    public function testMixedErrorsWithIgnoredPatterns(): void
    {
        $phpstanOutput = json_encode([
            'totals' => ['errors' => 3, 'file_errors' => 1],
            'files' => [
                '/path/to/file.php' => [
                    'errors' => 1,
                    'messages' => [
                        [
                            'message' => 'Method Test::method() has no return type specified.',
                            'line' => 15,
                            'identifier' => 'missingType.return'
                        ]
                    ]
                ]
            ],
            'errors' => [
                'Ignored error pattern #Unsafe usage of new static\\(\\)# was not matched in reported errors.',
                'Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M'
            ]
        ]);

        $errors = $this->parser->parse($phpstanOutput);

        // Should have 2 errors: the file error and the memory error (ignored pattern filtered out)
        $this->assertCount(2, $errors);
        
        // First error should be the file error
        $this->assertEquals('Method Test::method() has no return type specified.', $errors[0]->getMessage());
        $this->assertEquals('/path/to/file.php', $errors[0]->getFile());
        $this->assertEquals(15, $errors[0]->getLine());
        
        // Second error should be the memory error
        $this->assertEquals('Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M', $errors[1]->getMessage());
        $this->assertEquals('unknown', $errors[1]->getFile());
        $this->assertEquals(0, $errors[1]->getLine());
    }

    public function testNoIgnoredPatternWarnings(): void
    {
        $phpstanOutput = json_encode([
            'totals' => ['errors' => 1, 'file_errors' => 1],
            'files' => [
                '/path/to/file.php' => [
                    'errors' => 1,
                    'messages' => [
                        [
                            'message' => 'Access to an undefined property Test::$property.',
                            'line' => 25,
                            'identifier' => 'property.notFound'
                        ]
                    ]
                ]
            ],
            'errors' => []
        ]);

        $errors = $this->parser->parse($phpstanOutput);

        $this->assertCount(1, $errors);
        $this->assertEquals('Access to an undefined property Test::$property.', $errors[0]->getMessage());
        $this->assertEquals('/path/to/file.php', $errors[0]->getFile());
        $this->assertEquals(25, $errors[0]->getLine());
    }

    public function testOnlyIgnoredPatternWarnings(): void
    {
        $phpstanOutput = json_encode([
            'totals' => ['errors' => 3, 'file_errors' => 0],
            'files' => [],
            'errors' => [
                'Ignored error pattern #Trait .* is used zero times and is not analysed# was not matched in reported errors.',
                'Ignored error pattern #Unsafe usage of new static\\(\\)# was not matched in reported errors.',
                'Ignored error pattern #Variable \\$namespaceParts in empty\\(\\) always exists and is not falsy# was not matched in reported errors.'
            ]
        ]);

        $errors = $this->parser->parse($phpstanOutput);

        // Should have no errors since all were ignored pattern warnings
        $this->assertCount(0, $errors);
    }
}
