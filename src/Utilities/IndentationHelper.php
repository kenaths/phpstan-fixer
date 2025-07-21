<?php

declare(strict_types=1);

namespace PHPStanFixer\Utilities;

use PhpParser\Node;

/**
 * Helper class for preserving and managing code indentation.
 * 
 * This class provides utilities for:
 * - Detecting and preserving existing indentation styles (spaces vs tabs)
 * - Calculating proper indentation for AST nodes
 * - Formatting PHPDoc comments with consistent indentation
 * - Handling edge cases and error scenarios gracefully
 * 
 * @author PHPStan Fixer
 * @version 2.0
 * @since 1.0
 */
class IndentationHelper
{
    /**
     * Extract indentation from a line of code
     */
    public static function getLineIndentation(string $line): string
    {
        if (preg_match('/^(\s*)/', $line, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * Get indentation for a node based on its position in the original code
     * 
     * @param Node $node The AST node to get indentation for
     * @param string $originalContent The original source code
     * @return string The indentation string (spaces or tabs)
     * @throws InvalidArgumentException If inputs are invalid
     * @throws RuntimeException If unable to determine indentation
     */
    public static function getNodeIndentation(Node $node, string $originalContent): string
    {
        if (empty($originalContent)) {
            throw new \InvalidArgumentException('Original content cannot be empty');
        }
        
        try {
            $lines = explode("\n", $originalContent);
            $nodeLine = $node->getStartLine();
            
            if ($nodeLine <= 0 || $nodeLine > count($lines)) {
                throw new \RuntimeException('Invalid node line number: ' . $nodeLine);
            }
            
            $line = $lines[$nodeLine - 1] ?? '';
            $indentation = self::getLineIndentation($line);
            
            // If no indentation found, detect project indentation style
            if (empty($indentation)) {
                return self::detectIndentationType($originalContent);
            }
            
            return $indentation;
        } catch (\Throwable $e) {
            error_log('IndentationHelper getNodeIndentation error: ' . $e->getMessage());
            return '    '; // Default to 4 spaces as fallback
        }
    }
    
    /**
     * Create properly indented PHPDoc comment
     */
    public static function createIndentedDocComment(string $baseIndentation, string $docContent): string
    {
        $lines = explode("\n", $docContent);
        $indentedLines = [];
        
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                // First line (opening /**)
                $indentedLines[] = $baseIndentation . $line;
            } elseif ($i === count($lines) - 1) {
                // Last line (closing */)
                $indentedLines[] = $baseIndentation . ' */';
            } else {
                // Content lines
                $indentedLines[] = $baseIndentation . ' *' . ($line ? ' ' . ltrim($line) : '');
            }
        }
        
        return implode("\n", $indentedLines);
    }
    
    /**
     * Add PHPDoc comment while preserving indentation
     */
    public static function addDocCommentToNode(Node $node, string $docContent, string $originalContent): void
    {
        $baseIndentation = self::getNodeIndentation($node, $originalContent);
        
        // Format the docblock content
        $formattedDoc = self::formatDocBlock($docContent, $baseIndentation);
        
        // Set the doc comment
        $node->setDocComment(new \PhpParser\Comment\Doc($formattedDoc));
    }
    
    /**
     * Format a docblock with proper indentation
     * 
     * @param string $content The PHPDoc content to format
     * @param string $baseIndentation The base indentation to use
     * @return string The formatted PHPDoc block
     * @throws InvalidArgumentException If content is empty
     */
    public static function formatDocBlock(string $content, string $baseIndentation): string
    {
        if (empty($content)) {
            throw new \InvalidArgumentException('PHPDoc content cannot be empty');
        }
        
        try {
            // Remove existing /** and */ if present
            $content = preg_replace('/^\s*\/\*\*\s*\n?/', '', $content);
            $content = preg_replace('/\s*\*\/\s*$/', '', $content);
            
            if ($content === null) {
                throw new \RuntimeException('Failed to clean PHPDoc content');
            }
            
            $lines = explode("\n", $content);
            $formattedLines = [$baseIndentation . '/**'];
            
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if ($trimmedLine) {
                    // Remove leading * if present
                    $trimmedLine = preg_replace('/^\*\s*/', '', $trimmedLine);
                    $formattedLines[] = $baseIndentation . ' * ' . $trimmedLine;
                } else {
                    $formattedLines[] = $baseIndentation . ' *';
                }
            }
            
            $formattedLines[] = $baseIndentation . ' */';
            
            return implode("\n", $formattedLines);
        } catch (\Throwable $e) {
            error_log('IndentationHelper formatDocBlock error: ' . $e->getMessage());
            // Return a basic formatted docblock as fallback
            return $baseIndentation . '/**\n' . $baseIndentation . ' * ' . trim($content) . '\n' . $baseIndentation . ' */';
        }
    }
    
    /**
     * Update existing PHPDoc comment while preserving indentation
     */
    public static function updateDocComment(Node $node, string $newContent, string $originalContent): void
    {
        $baseIndentation = self::getNodeIndentation($node, $originalContent);
        $formattedDoc = self::formatDocBlock($newContent, $baseIndentation);
        $node->setDocComment(new \PhpParser\Comment\Doc($formattedDoc));
    }
    
    /**
     * Extract type information from PHPDoc comment
     */
    public static function extractTypeFromDocComment(?string $docComment): ?string
    {
        if (!$docComment) {
            return null;
        }
        
        // Look for @var, @param, or @return tags
        if (preg_match('/@(?:var|param|return)\s+([^\s\*]+)/', $docComment, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Check if indentation is consistent (tabs vs spaces)
     */
    public static function detectIndentationType(string $content): string
    {
        $lines = explode("\n", $content);
        $tabCount = 0;
        $spaceCount = 0;
        
        foreach ($lines as $line) {
            if (preg_match('/^(\t+)/', $line)) {
                $tabCount++;
            } elseif (preg_match('/^( {2,})/', $line)) {
                $spaceCount++;
            }
        }
        
        return $tabCount > $spaceCount ? "\t" : "    ";
    }
    
    /**
     * Normalize indentation to match the detected style
     */
    public static function normalizeIndentation(string $content, string $indentationType): string
    {
        if ($indentationType === "\t") {
            // Convert spaces to tabs
            return preg_replace('/^(    )+/m', function($matches) {
                return str_repeat("\t", strlen($matches[0]) / 4);
            }, $content);
        } else {
            // Convert tabs to spaces
            return preg_replace('/^\t+/m', function($matches) use ($indentationType) {
                return str_repeat($indentationType, strlen($matches[0]));
            }, $content);
        }
    }
}