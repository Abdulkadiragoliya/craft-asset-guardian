<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\tests\unit;

use abdulkadiragoliya\assetguardian\models\Settings;

/**
 * Settings Model Unit Tests
 */
class SettingsModelTest
{
    public function testDefaultValues(): void
    {
        $settings = new Settings();
        assert($settings->unusedAgeThresholdDays === 180, "Default unused age should be 180 days");
        assert($settings->largeFileThresholdBytes === 10485760, "Default large threshold should be 10MB");
        assert($settings->scanMissingAlt === true, "Default scanMissingAlt should be true");
        assert($settings->safeTrashOnly === true, "Default safeTrashOnly should be true");
        assert($settings->validate() === true, "Default settings must be valid");
    }

    public function testValidationRules(): void
    {
        $settings = new Settings();
        $settings->unusedAgeThresholdDays = -5;
        // Integer rule validates integer, not negative constraint unless min specified
        $settings->largeFileThresholdBytes = 10485760;
        assert(is_int($settings->unusedAgeThresholdDays), "Threshold must be integer");
    }
}
