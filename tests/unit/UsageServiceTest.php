<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\tests\unit;

use abdulkadiragoliya\assetguardian\services\UsageService;

/**
 * UsageService Unit Tests
 */
class UsageServiceTest
{
    public function testDetermineRiskForRecentAsset(): void
    {
        $service = new UsageService();
        // Asset created 10 days ago (within 180-day grace period)
        $dateCreated = (new \DateTime('-10 days'))->format('Y-m-d H:i:s');
        $risk = $service->determineRisk(0, $dateCreated);
        assert($risk === 'medium', "Recent unreferenced asset within grace period should be marked medium risk, got {$risk}");
    }

    public function testDetermineRiskForOldAsset(): void
    {
        $service = new UsageService();
        // Asset created 200 days ago (older than 180-day threshold)
        $dateCreated = (new \DateTime('-200 days'))->format('Y-m-d H:i:s');
        $risk = $service->determineRisk(0, $dateCreated);
        assert($risk === 'low', "Old unreferenced asset beyond threshold should be marked low risk, got {$risk}");
    }

    public function testDetermineRiskForActiveAsset(): void
    {
        $service = new UsageService();
        $dateCreated = (new \DateTime('-200 days'))->format('Y-m-d H:i:s');
        $risk = $service->determineRisk(3, $dateCreated);
        assert($risk === 'high', "Actively referenced asset must be high risk, got {$risk}");
    }
}
