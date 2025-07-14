<?php

namespace PHPStanFixer\Util;

class AutoloadUtil
{
    private readonly string $composerPath;

    public function __construct(?string $composerFile = null, ?string $projectRoot = null, private readonly bool $dev = true)
    {
        $this->composerPath = ($composerFile ?: trim(getenv('COMPOSER') ?: '')) ?: './composer.json';
    }


    public function getBinFolder(): string
    {
        if (! file_exists($this->composerPath)) {
            throw new \RuntimeException('Unable to find composer.json');
        }

        $composerContent = file_get_contents($this->composerPath);

        if ($composerContent === false) {
            throw new \RuntimeException('Unable to read composer.json');
        }

        $composer = json_decode($composerContent, true);
        if (! \is_array($composer)) {
            throw new \RuntimeException('Invalid composer.json file');
        }

        return $composer['config']['bin-dir'] ?? 'vendor/bin';
    }
}