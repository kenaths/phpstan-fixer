<?php

declare(strict_types=1);

namespace PHPStanFixer\Tests;

use PHPUnit\Framework\TestCase;
use PHPStanFixer\Analyzers\SmartTypeAnalyzer;
use PhpParser\ParserFactory;

class SmartTypeAnalyzerTest extends TestCase
{
    private SmartTypeAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SmartTypeAnalyzer();
    }

    public function testBasicPropertyTypeInference(): void
    {
        $code = '<?php
class TestClass {
    protected $map = ["name" => "my-name"];
}';

        $this->analyzer->analyze($this->parseCode($code));
        
        $type = $this->analyzer->getPropertyType('TestClass', 'map');
        $this->assertNotNull($type);
        $this->assertStringContainsString('array', $type);
    }

    public function testCrossClassPropertyAccess(): void
    {
        $code = '<?php
class A {
    protected $map = ["name" => "my-name"];
}

class B {
    public function __construct(protected A $a) {}
    
    public function setMapForA($data) {
        $this->a->map = $data;
    }
}';

        $this->analyzer->analyze($this->parseCode($code));

        // Check that A's map property gets the original type
        $aMapType = $this->analyzer->getPropertyType('A', 'map');
        $this->assertNotNull($aMapType);
        $this->assertStringContainsString('array', $aMapType);

        // Check that B's constructor parameter was registered
        $this->assertNotNull($this->analyzer->getParameterType('B', 'setMapForA', 'data'));
    }

    public function testConstructorParameterRegistration(): void
    {
        $code = '<?php
class Service {
    public function __construct(protected Database $db, protected string $name) {}
}';

        $this->analyzer->analyze($this->parseCode($code));

        // Debug the internal state
        $debug = $this->analyzer->getDebugInfo();
        
        // Check that constructor parameters are registered
        $this->assertNotEmpty($debug['constructorParameters']);
        $this->assertArrayHasKey('Service', $debug['constructorParameters']);
        
        // Test that constructor parameters are registered with their types
        $this->assertEquals('Database', $this->analyzer->getParameterType('Service', '__construct', 'db'));
        $this->assertEquals('string', $this->analyzer->getParameterType('Service', '__construct', 'name'));
    }

    public function testComplexCrossClassScenario(): void
    {
        $code = '<?php
class ConfigMap {
    public $settings = ["debug" => true, "timeout" => 30];
}

class Application {
    public function __construct(protected ConfigMap $config) {}
    
    public function updateConfig(array $newSettings) {
        $this->config->settings = $newSettings;
    }
    
    public function setSingleSetting(string $key, $value) {
        $this->config->settings[$key] = $value;
    }
}';

        $this->analyzer->analyze($this->parseCode($code));

        // Check original property type
        $configType = $this->analyzer->getPropertyType('ConfigMap', 'settings');
        $this->assertNotNull($configType);
        $this->assertStringContainsString('array', $configType);

        // Check method parameter types
        $newSettingsType = $this->analyzer->getParameterType('Application', 'updateConfig', 'newSettings');
        $this->assertNotNull($newSettingsType);

        $keyType = $this->analyzer->getParameterType('Application', 'setSingleSetting', 'key');
        $this->assertEquals('string', $keyType);
    }

    public function testArrayTypeInference(): void
    {
        $code = '<?php
class DataStore {
    protected $data = [
        "users" => ["alice", "bob"],
        "counts" => [1, 2, 3]
    ];
    
    public function addUser(string $name) {
        $this->data["users"][] = $name;
    }
}';

        $this->analyzer->analyze($this->parseCode($code));

        $dataType = $this->analyzer->getPropertyType('DataStore', 'data');
        $this->assertNotNull($dataType);
        $this->assertStringContainsString('array', $dataType);

        $nameType = $this->analyzer->getParameterType('DataStore', 'addUser', 'name');
        $this->assertEquals('string', $nameType);
    }

    public function testNestedPropertyAccess(): void
    {
        $code = '<?php
class User {
    public $profile = ["name" => "John", "age" => 30];
}

class UserManager {
    public function __construct(protected User $user) {}
    
    public function updateProfile(array $profileData) {
        $this->user->profile = $profileData;
    }
}

class AdminPanel {
    public function __construct(protected UserManager $userManager) {}
    
    public function resetUserProfile() {
        $this->userManager->user->profile = [];
    }
}';

        $this->analyzer->analyze($this->parseCode($code));

        // Check original property type
        $profileType = $this->analyzer->getPropertyType('User', 'profile');
        $this->assertNotNull($profileType);
        $this->assertStringContainsString('array', $profileType);

        // Check parameter type
        $profileDataType = $this->analyzer->getParameterType('UserManager', 'updateProfile', 'profileData');
        $this->assertNotNull($profileDataType);
    }

    public function testUnionTypeHandling(): void
    {
        $code = '<?php
class FlexibleStore {
    protected $value = null;
    
    public function setValue($data) {
        $this->value = $data;
    }
    
    public function setString(string $str) {
        $this->value = $str;
    }
    
    public function setNumber(int $num) {
        $this->value = $num;
    }
}';

        $this->analyzer->analyze($this->parseCode($code));

        $valueType = $this->analyzer->getPropertyType('FlexibleStore', 'value');
        $this->assertNotNull($valueType);
        
        // Should infer a union type based on assignments
        $this->assertTrue(
            str_contains($valueType, 'string') || 
            str_contains($valueType, 'int') || 
            str_contains($valueType, 'null') ||
            $valueType === 'mixed'
        );
    }

    private function parseCode(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        return $parser->parse($code) ?? [];
    }
}
