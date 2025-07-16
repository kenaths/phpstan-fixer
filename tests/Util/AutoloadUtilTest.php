<?php

namespace PHPStanFixer\Tests\Util;

use PHPStanFixer\Util\AutoloadUtil;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AutoloadUtilTest extends TestCase
{
    private string $tempComposerFile;

    protected function setUp(): void
    {
        $this->tempComposerFile = tempnam(sys_get_temp_dir(), 'composer') . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempComposerFile)) {
            unlink($this->tempComposerFile);
        }
    }

    public function testGetBinFolderReturnsDefaultVendorBin(): void
    {
        file_put_contents($this->tempComposerFile, json_encode([]));
        $autoloadUtil = new AutoloadUtil($this->tempComposerFile);

        $this->assertEquals('vendor/bin', $autoloadUtil->getBinFolder());
    }

    public function testGetBinFolderReturnsCustomBinDir(): void
    {
        file_put_contents($this->tempComposerFile, json_encode(['config' => ['bin-dir' => 'custom/bin']]));
        $autoloadUtil = new AutoloadUtil($this->tempComposerFile);

        $this->assertEquals('custom/bin', $autoloadUtil->getBinFolder());
    }

    public function testGetBinFolderThrowsExceptionForMissingComposerFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to find composer.json');

        $autoloadUtil = new AutoloadUtil('/nonexistent/composer.json');
        $autoloadUtil->getBinFolder();
    }

    public function testGetBinFolderThrowsExceptionForInvalidComposerContent(): void
    {
        file_put_contents($this->tempComposerFile, 'invalid json');
        $autoloadUtil = new AutoloadUtil($this->tempComposerFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json file');

        $autoloadUtil->getBinFolder();
    }
}