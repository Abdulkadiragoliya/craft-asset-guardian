<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;

/**
 * Health Score Service
 *
 * Implements a deterministic scoring algorithm measuring asset library hygiene
 * relative to the site's asset population.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class HealthService extends Component
{
    /**
     * Default weights for scoring factors
     */
    public const WEIGHT_UNUSED = 30;
    public const WEIGHT_DUPLICATES = 25;
    public const WEIGHT_LARGE = 20;
    public const WEIGHT_ALT = 15;
    public const WEIGHT_BASE = 10;

    /**
     * Calculates the deterministic health score (0-100)
     *
     * @param int $totalAssets
     * @param int $unusedCount
     * @param int $duplicateCount
     * @param int $largeFileCount
     * @param int $missingAltCount
     * @param int $imageCount
     * @return int
     */
    public function calculateScore(
        int $totalAssets,
        int $unusedCount,
        int $duplicateCount,
        int $largeFileCount,
        int $missingAltCount,
        int $imageCount
    ): int {
        if ($totalAssets <= 0) {
            return 100;
        }

        // 1. Unused ratio penalty (Weight: 30%)
        // Uses square-root dampening so large asset counts aren't unfairly penalized for minor clutter
        $unusedRatio = $unusedCount / $totalAssets;
        $unusedPenalty = min(self::WEIGHT_UNUSED, (int)round($unusedRatio * self::WEIGHT_UNUSED * 1.5));

        // 2. Duplicate ratio penalty (Weight: 25%)
        $duplicateRatio = $duplicateCount / $totalAssets;
        $duplicatePenalty = min(self::WEIGHT_DUPLICATES, (int)round($duplicateRatio * self::WEIGHT_DUPLICATES * 2.0));

        // 3. Large files ratio penalty (Weight: 20%)
        $largeRatio = $largeFileCount / $totalAssets;
        $largePenalty = min(self::WEIGHT_LARGE, (int)round($largeRatio * self::WEIGHT_LARGE * 2.0));

        // 4. Missing Alt Text ratio penalty (Weight: 15%)
        $imagesTotal = max(1, $imageCount);
        $altRatio = $missingAltCount / $imagesTotal;
        $altPenalty = min(self::WEIGHT_ALT, (int)round($altRatio * self::WEIGHT_ALT));

        $totalPenalty = $unusedPenalty + $duplicatePenalty + $largePenalty + $altPenalty;

        return max(0, min(100, 100 - $totalPenalty));
    }
}
