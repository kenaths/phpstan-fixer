<?php

declare(strict_types=1);

namespace PHPStanFixer\Fixers;

use PHPStanFixer\Cache\TypeCache;

abstract class CacheAwareFixer extends AbstractFixer
{
    protected ?TypeCache $typeCache = null;
    protected ?string $currentFile = null;

    public function setTypeCache(?TypeCache $typeCache): void
    {
        $this->typeCache = $typeCache;
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
    }

    protected function reportPropertyType(string $className, string $propertyName, string $type, ?string $nativeType = null): void
    {
        if ($this->typeCache && $this->currentFile) {
            $this->typeCache->setFilePathForClass($className, $this->currentFile);
            $this->typeCache->setPropertyType($className, $propertyName, $type, $nativeType);
        }
    }

    protected function reportMethodTypes(string $className, string $methodName, array $paramTypes, ?string $returnType, ?string $phpDocReturn = null): void
    {
        if ($this->typeCache && $this->currentFile) {
            $this->typeCache->setFilePathForClass($className, $this->currentFile);
            $this->typeCache->setMethodTypes($className, $methodName, $paramTypes, $returnType, $phpDocReturn);
        }
    }

    protected function lookupPropertyType(string $className, string $propertyName): ?array
    {
        if ($this->typeCache) {
            return $this->typeCache->getPropertyType($className, $propertyName);
        }
        return null;
    }

    protected function lookupMethodReturnType(string $className, string $methodName): ?array
    {
        if ($this->typeCache) {
            return $this->typeCache->getMethodReturnType($className, $methodName);
        }
        return null;
    }

    protected function lookupMethodParameterTypes(string $className, string $methodName): ?array
    {
        if ($this->typeCache) {
            return $this->typeCache->getMethodParameterTypes($className, $methodName);
        }
        return null;
    }
}