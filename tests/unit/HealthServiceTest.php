<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\tests\unit;

use abdulkadiragoliya\assetguardian\services\HealthService;

/**
 * HealthService Unit Tests
 */
class HealthServiceTest
{
    public function testPerfectScoreOnEmptyLibrary(): void
    {
        $service = new HealthService();
        $score = $service->calculateScore(0, 0, 0, 0, 0, 0);
        assert($score === 100, "Expected score 100 for zero assets, got {$score}");
    }

    public function testClampingToZero(): void
    {
        $service = new HealthService();
        $score = $service->calculateScore(100, 100, 100, 100, 100, 100);
        assert($score >= 0 && $score <= 100, "Score should be clamped between 0 and 100, got {$score}");
    }

    public function testDeterministicCalculation(): void
    {
        $service = new HealthService();
        $score1 = $service->calculateScore(100, 10, 5, 2, 8, 40);
        $score2 = $service->calculateScore(100, 10, 5, 2, 8, 40);
        assert($score1 === $score2, "Calculations must be deterministic");
        assert($score1 < 100 && $score1 > 50, "Expected moderate score, got {$score1}");
    }
}
