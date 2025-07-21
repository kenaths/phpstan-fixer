<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

/**
 * Utility class for detecting project root directory
 */
class ProjectRootDetector
{
    /**
     * Detect project root from current file path
     */
    public static function detectFromFilePath(?string $currentFile): string
    {
        if (!$currentFile) {
            return getcwd() ?: '.';
        }
        
        $projectRoot = dirname($currentFile);
        
        // Walk up the directory tree looking for composer.json or .git
        while ($projectRoot !== '/' && $projectRoot !== '') {
            if (file_exists($projectRoot . '/composer.json') || 
                file_exists($projectRoot . '/.git')) {
                return $projectRoot;
            }
            $projectRoot = dirname($projectRoot);
        }
        
        // Fallback to current working directory if no composer.json found
        if ($projectRoot === '/' || !is_dir($projectRoot)) {
            return getcwd() ?: '.';
        }
        
        return $projectRoot;
    }
    
    /**
     * Detect project root from given paths
     */
    public static function detectFromPaths(array $paths): string
    {
        // Try to find composer.json or .git directory
        foreach ($paths as $path) {
            $currentPath = realpath($path);
            if (!$currentPath) {
                continue;
            }
            
            // If it's a file, get its directory
            if (is_file($currentPath)) {
                $currentPath = dirname($currentPath);
            }
            
            // Walk up the directory tree
            while ($currentPath !== '/' && $currentPath !== '') {
                if (file_exists($currentPath . '/composer.json') || 
                    file_exists($currentPath . '/.git')) {
                    return $currentPath;
                }
                $currentPath = dirname($currentPath);
            }
        }
        
        // Fallback to the first provided path's directory
        if (!empty($paths)) {
            $firstPath = realpath($paths[0]);
            if ($firstPath) {
                return is_dir($firstPath) ? $firstPath : dirname($firstPath);
            }
        }
        
        // Last resort: current working directory
        return getcwd() ?: '.';
    }
}