<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/unit/HealthServiceTest.php';
require_once __DIR__ . '/unit/SettingsModelTest.php';
require_once __DIR__ . '/unit/UsageServiceTest.php';

use abdulkadiragoliya\assetguardian\tests\unit\HealthServiceTest;
use abdulkadiragoliya\assetguardian\tests\unit\SettingsModelTest;
use abdulkadiragoliya\assetguardian\tests\unit\UsageServiceTest;

echo "\n--- Running Asset Guardian Test Suite ---\n\n";

$passCount = 0;
$failCount = 0;

$suites = [
    new HealthServiceTest(),
    new SettingsModelTest(),
    new UsageServiceTest(),
];

foreach ($suites as $suite) {
    $class = get_class($suite);
    echo "Running " . basename(str_replace('\\', '/', $class)) . "...\n";
    $methods = get_class_methods($suite);

    foreach ($methods as $method) {
        if (str_starts_with($method, 'test')) {
            try {
                $suite->$method();
                echo "  [PASS] {$method}\n";
                $passCount++;
            } catch (\Throwable $e) {
                echo "  [FAIL] {$method}: " . $e->getMessage() . "\n";
                $failCount++;
            }
        }
    }
    echo "\n";
}

echo "Summary: {$passCount} passed, {$failCount} failed.\n";
exit($failCount === 0 ? 0 : 1);
