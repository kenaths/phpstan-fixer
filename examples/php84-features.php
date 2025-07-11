<?php

declare(strict_types=1);

// Example file demonstrating PHP 8.4 features that need fixing

// 1. Class with missing property types
class User
{
    private $id;
    private $name;
    private $email;
    private $roles = [];
    
    public function __construct($id, $name, $email)
    {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
    }
    
    // Missing return type
    public function getId()
    {
        return $this->id;
    }
    
    // Missing parameter and return types
    public function setName($name)
    {
        $this->name = $name;
    }
    
    // Method that could return union type
    public function getIdentifier($type)
    {
        if ($type === 'id') {
            return $this->id;
        }
        return $this->email;
    }
}

// 2. Enum without backing type
enum Status
{
    case PENDING;
    case ACTIVE;
    case COMPLETED;
}

// 3. Class that could use constructor promotion
class Product
{
    private string $name;
    private float $price;
    private ?string $description;
    
    public function __construct(string $name, float $price, ?string $description = null)
    {
        $this->name = $name;
        $this->price = $price;
        $this->description = $description;
    }
}

// 4. Class with readonly properties
class Config
{
    private $apiKey;
    private $environment;
    
    public function __construct($apiKey, $environment)
    {
        $this->apiKey = $apiKey;
        $this->environment = $environment;
    }
    
    public function getApiKey()
    {
        return $this->apiKey;
    }
}

// 5. Function with missing types and weak comparison
function processData($data)
{
    if ($data == null) {
        return false;
    }
    
    $result = [];
    $temp = "unused variable";
    
    foreach ($data as $item) {
        if (is_array($item)) {
            $result[] = $item;
        }
    }
    
    return $result;
}

// 6. Match expression example
function getStatusMessage($status)
{
    return match($status) {
        'pending' => 'Waiting for approval',
        'active' => 'Currently active',
        'completed' => 'Task completed',
        default => 'Unknown status'
    };
}

// 7. First-class callable usage
class EventHandler
{
    private $handlers = [];
    
    public function addHandler($event, $handler)
    {
        $this->handlers[$event][] = $handler;
    }
    
    public function trigger($event, $data)
    {
        if (isset($this->handlers[$event])) {
            foreach ($this->handlers[$event] as $handler) {
                $handler($data);
            }
        }
    }
}

// 8. DNF (Disjunctive Normal Form) types example
interface Stringable {}
interface Countable {}

class DataProcessor
{
    // Should have (Stringable&Countable)|array|null type
    public function process($data)
    {
        if ($data === null) {
            return null;
        }
        
        if (is_array($data)) {
            return count($data);
        }
        
        if ($data instanceof Stringable && $data instanceof Countable) {
            return strlen((string) $data);
        }
        
        return 0;
    }
}

// 9. Property hooks (PHP 8.4 - commented out as parsers don't support it yet)
// class AsymmetricVisibilityExample
// {
//     public private(set) string $readOnlyFromOutside;
//     
//     public function __construct(string $value)
//     {
//         $this->readOnlyFromOutside = $value;
//     }
// }