<?php

declare(strict_types=1);

namespace PHPStanFixer\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Analyzes array usage to determine specific key and value types
 */
class ArrayTypeAnalyzer
{
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Analyze an array property or variable to determine its key and value types
     * 
     * @return array{key: string, value: string}
     */
    public function analyzeArrayType(Node $node, string $arrayName, ?Node\Stmt\ClassMethod $method = null): array
    {
        $keyTypes = [];
        $valueTypes = [];

        // Check default value if it's a property or parameter
        if ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                if ($prop->name->toString() === $arrayName && $prop->default instanceof Node\Expr\Array_) {
                    $this->analyzeArrayExpression($prop->default, $keyTypes, $valueTypes);
                }
            }
        }

        if ($node instanceof Node\Param && $node->default instanceof Node\Expr\Array_) {
            $this->analyzeArrayExpression($node->default, $keyTypes, $valueTypes);
        }

        // Analyze usage in method body
        if ($method !== null) {
            $this->analyzeArrayUsageInMethod($method, $arrayName, $keyTypes, $valueTypes);
        }

        // Determine the most specific type
        $keyType = $this->determineType($keyTypes, 'int'); // Default to int for numeric arrays
        $valueType = $this->determineType($valueTypes, 'mixed');

        return ['key' => $keyType, 'value' => $valueType];
    }

    /**
     * Analyze an array expression to extract key and value types
     * 
     * @param array<string> $keyTypes
     * @param array<string> $valueTypes
     */
    private function analyzeArrayExpression(Node\Expr\Array_ $array, array &$keyTypes, array &$valueTypes): void
    {
        foreach ($array->items as $item) {

            // Analyze key
            if ($item->key !== null) {
                $keyType = $this->inferExpressionType($item->key);
                if ($keyType !== null) {
                    $keyTypes[] = $keyType;
                }
            } else {
                // No explicit key means numeric
                $keyTypes[] = 'int';
            }

            // Analyze value
            $valueType = $this->inferExpressionType($item->value);
            if ($valueType !== null) {
                $valueTypes[] = $valueType;
            }
        }
    }

    /**
     * Analyze array usage within a method body
     * 
     * @param array<string> $keyTypes
     * @param array<string> $valueTypes
     */
    private function analyzeArrayUsageInMethod(Node\Stmt\ClassMethod $method, string $arrayName, array &$keyTypes, array &$valueTypes): void
    {
        // Find all array access and assignments
        $arrayAccesses = $this->nodeFinder->find($method, function (Node $node) use ($arrayName) {
            if (!$node instanceof Node\Expr\ArrayDimFetch) {
                return false;
            }
            
            // Check if it's accessing our array variable
            if ($node->var instanceof Node\Expr\Variable && $node->var->name === $arrayName) {
                return true;
            }
            
            // Check if it's accessing our array property (e.g., $this->arrayName)
            if ($node->var instanceof Node\Expr\PropertyFetch 
                && $node->var->var instanceof Node\Expr\Variable 
                && $node->var->var->name === 'this'
                && $node->var->name instanceof Node\Identifier
                && $node->var->name->name === $arrayName) {
                return true;
            }
            
            return false;
        });

        foreach ($arrayAccesses as $access) {
            if (!$access instanceof Node\Expr\ArrayDimFetch) {
                continue;
            }
            // Analyze array key
            if ($access->dim !== null) {
                $keyType = $this->inferExpressionType($access->dim);
                if ($keyType !== null) {
                    $keyTypes[] = $keyType;
                }
            }
        }

        // Find assignments to array
        $assignments = $this->nodeFinder->find($method, function (Node $node) use ($arrayName) {
            if (!($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\ArrayDimFetch)) {
                return false;
            }
            
            $arrayAccess = $node->var;
            
            // Check if it's assigning to our array variable
            if ($arrayAccess->var instanceof Node\Expr\Variable && $arrayAccess->var->name === $arrayName) {
                return true;
            }
            
            // Check if it's assigning to our array property (e.g., $this->arrayName)
            if ($arrayAccess->var instanceof Node\Expr\PropertyFetch 
                && $arrayAccess->var->var instanceof Node\Expr\Variable 
                && $arrayAccess->var->var->name === 'this'
                && $arrayAccess->var->name instanceof Node\Identifier
                && $arrayAccess->var->name->name === $arrayName) {
                return true;
            }
            
            return false;
        });

        foreach ($assignments as $assignment) {
            if (!$assignment instanceof Node\Expr\Assign) {
                continue;
            }
            // Analyze assigned value
            $valueType = $this->inferExpressionType($assignment->expr);
            if ($valueType !== null) {
                $valueTypes[] = $valueType;
            }
        }

        // Check foreach loops
        $foreachLoops = $this->nodeFinder->find($method, function (Node $node) use ($arrayName) {
            if (!$node instanceof Node\Stmt\Foreach_) {
                return false;
            }
            
            // Check if it's iterating over our array variable
            if ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $arrayName) {
                return true;
            }
            
            // Check if it's iterating over our array property (e.g., $this->arrayName)
            if ($node->expr instanceof Node\Expr\PropertyFetch 
                && $node->expr->var instanceof Node\Expr\Variable 
                && $node->expr->var->name === 'this'
                && $node->expr->name instanceof Node\Identifier
                && $node->expr->name->name === $arrayName) {
                return true;
            }
            
            return false;
        });

        foreach ($foreachLoops as $loop) {
            if (!$loop instanceof Node\Stmt\Foreach_) {
                continue;
            }
            // If foreach has key => value, we might get hints about types
            if ($loop->keyVar !== null && $loop->keyVar instanceof Node\Expr\Variable) {
                // The key variable gives us hints about key type
                $keyUsages = $this->analyzeVariableUsage($method, $loop->keyVar);
                foreach ($keyUsages as $usage) {
                    $keyTypes[] = $usage;
                }
            }

            if ($loop->valueVar instanceof Node\Expr\Variable) {
                // Analyze how the value variable is used
                $valueUsages = $this->analyzeVariableUsage($method, $loop->valueVar);
                foreach ($valueUsages as $usage) {
                    $valueTypes[] = $usage;
                }
            }
        }
    }

    /**
     * Analyze how a variable is used to infer its type
     * 
     * @return array<string>
     */
    private function analyzeVariableUsage(Node $scope, Node\Expr\Variable $var): array
    {
        $types = [];
        $varName = is_string($var->name) ? $var->name : null;
        
        if ($varName === null) {
            return $types;
        }

        // Find method calls on the variable
        $methodCalls = $this->nodeFinder->find($scope, function (Node $node) use ($varName) {
            return $node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === $varName;
        });

        foreach ($methodCalls as $call) {
            if (!$call instanceof Node\Expr\MethodCall) {
                continue;
            }
            if ($call->name instanceof Node\Identifier) {
                $methodName = $call->name->toString();
                // String methods
                if (in_array($methodName, ['trim', 'toLowerCase', 'toUpperCase', 'substr', 'replace'])) {
                    $types[] = 'string';
                }
                // Array methods would indicate it's an array itself
                elseif (in_array($methodName, ['count', 'push', 'pop'])) {
                    $types[] = 'array';
                }
            }
        }

        // Find function calls with the variable
        $funcCalls = $this->nodeFinder->find($scope, function (Node $node) use ($varName) {
            return $node instanceof Node\Expr\FuncCall
                && count($node->args) > 0
                && $node->args[0]->value instanceof Node\Expr\Variable
                && $node->args[0]->value->name === $varName;
        });

        foreach ($funcCalls as $call) {
            if (!$call instanceof Node\Expr\FuncCall) {
                continue;
            }
            if ($call->name instanceof Node\Name) {
                $funcName = $call->name->toString();
                // String functions
                if (in_array($funcName, ['strlen', 'strtolower', 'strtoupper', 'trim', 'str_replace'])) {
                    $types[] = 'string';
                }
                // Integer functions
                elseif (in_array($funcName, ['intval', 'abs'])) {
                    $types[] = 'int';
                }
                // Float functions
                elseif (in_array($funcName, ['floatval', 'round', 'ceil', 'floor'])) {
                    $types[] = 'float';
                }
            }
        }

        return $types;
    }

    /**
     * Infer type from an expression
     */
    private function inferExpressionType(Node\Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => 'string',
            $expr instanceof Node\Scalar\LNumber => 'int',
            $expr instanceof Node\Scalar\DNumber => 'float',
            $expr instanceof Node\Expr\Array_ => 'array',
            $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'true' => 'bool',
            $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'false' => 'bool',
            $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null' => 'null',
            $expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name => $expr->class->toString(),
            $expr instanceof Node\Expr\Variable && $expr->name === 'this' => 'object',
            $expr instanceof Node\Expr\FuncCall => $this->inferFunctionReturnType($expr),
            $expr instanceof Node\Expr\MethodCall => 'mixed', // Would need more context
            $expr instanceof Node\Expr\PropertyFetch => 'mixed', // Would need more context
            $expr instanceof Node\Expr\BinaryOp\Concat => 'string',
            $expr instanceof Node\Expr\BinaryOp\Plus => 'int|float',
            default => null,
        };
    }

    /**
     * Infer return type from function call
     */
    private function inferFunctionReturnType(Node\Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof Node\Name) {
            return null;
        }

        $funcName = $call->name->toString();
        return match ($funcName) {
            'count', 'strlen', 'strpos', 'time', 'rand', 'mt_rand' => 'int',
            'explode', 'array_merge', 'array_map', 'array_filter', 'array_values', 'array_keys' => 'array',
            'implode', 'trim', 'strtolower', 'strtoupper', 'substr', 'str_replace' => 'string',
            'is_null', 'is_string', 'is_array', 'is_int', 'isset', 'empty' => 'bool',
            'floatval', 'round', 'ceil', 'floor' => 'float',
            default => null,
        };
    }

    /**
     * Determine the most specific type from a list of types
     * 
     * @param array<string> $types
     */
    private function determineType(array $types, string $default = 'mixed'): string
    {
        if (empty($types)) {
            return $default;
        }

        // Count occurrences
        $typeCounts = array_count_values($types);
        
        // If all types are the same, use that type
        if (count($typeCounts) === 1) {
            return array_key_first($typeCounts);
        }

        // Handle numeric types
        if (isset($typeCounts['int']) && isset($typeCounts['float'])) {
            return 'int|float';
        }

        // If we have multiple types, create a union
        $uniqueTypes = array_keys($typeCounts);
        
        // Remove null and handle it separately
        $hasNull = in_array('null', $uniqueTypes);
        $uniqueTypes = array_diff($uniqueTypes, ['null']);

        if (count($uniqueTypes) === 0) {
            return 'null';
        }

        if (count($uniqueTypes) === 1) {
            $type = reset($uniqueTypes);
            return $hasNull ? $type . '|null' : $type;
        }

        // Multiple types - return the most common one or a union of top 2
        arsort($typeCounts);
        $topTypes = array_slice(array_keys($typeCounts), 0, 2);
        
        if ($hasNull) {
            $topTypes[] = 'null';
        }

        return implode('|', $topTypes);
    }
}