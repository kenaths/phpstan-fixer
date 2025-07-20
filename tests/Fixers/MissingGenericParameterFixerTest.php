<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests\Fixers;

use PHPStanFixer\Fixers\MissingGenericParameterFixer;
use PHPStanFixer\ValueObjects\Error;
use PHPStanFixer\Runner\PHPStanRunner;
use PHPUnit\Framework\TestCase;

class MissingGenericParameterFixerTest extends TestCase
{
    private MissingGenericParameterFixer $fixer;
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private PHPStanRunner $mockRunner;

    protected function setUp(): void
    {
        $this->mockRunner = $this->createMock(PHPStanRunner::class);
        $this->fixer = new MissingGenericParameterFixer($this->mockRunner);
    }

    public function testCanFixGenericParameterError(): void
    {
        $error = new Error(
            'test.php',
            10,
            'Method TestClass::__construct() has parameter $components with generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );
        
        $this->assertTrue($this->fixer->canFix($error));
    }

    public function testFixesGenericCollectionParameter(): void
    {
        $code = <<<'PHP'
<?php
class GetFirstNotNull
{
    public function __construct(array $columns, Collection $components) {}
}
PHP;

        // Mock PHPStan feedback
        /** @var \PHPUnit\Framework\MockObject\MockObject $this->mockRunner */
        $this->mockRunner->expects($this->any())->method('analyze')->willReturn(json_encode([
            'files' => [
                'temp_file' => [
                    'messages' => [
                        [
                            'line' => 4,
                            'message' => 'Parameter #1 $callback of method Collection::first() expects callable(mixed, int): bool, callable(Column, int): bool given.',
                            'identifier' => 'argument.type'
                        ]
                    ]
                ]
            ]
        ]));

        $error = new Error(
            'test.php',
            4,
            'Method GetFirstNotNull::__construct() has parameter $components with generic class Illuminate\Support\Collection but does not specify its types: TKey, TValue'
        );

        $result = $this->fixer->fix($code, $error);
        
        $this->assertStringContainsString('@param Collection<int, Column> $components', $result);
    }
} 