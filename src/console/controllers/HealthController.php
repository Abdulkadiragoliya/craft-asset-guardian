<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\console\ExitCode;

/**
 * CLI Health Controller for Asset Guardian
 *
 * Usage:
 *   php craft asset-guardian/health
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\console\controllers
 * @since 1.0.0
 */
class HealthController extends Controller
{
    /**
     * Displays a terminal summary of current asset health scores and statistics
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $latestScan = $scanService->getLatestCompletedScan();

        $this->stdout("\n==========================================\n", Console::FG_CYAN);
        $this->stdout("       ASSET GUARDIAN HEALTH STATUS       \n", Console::FG_CYAN, Console::BOLD);
        $this->stdout("==========================================\n\n", Console::FG_CYAN);

        if (!$latestScan) {
            $this->stdout("No completed scan found.\n", Console::FG_YELLOW);
            $this->stdout("Run 'php craft asset-guardian/scan' to perform an initial library audit.\n\n");
            return ExitCode::OK;
        }

        $healthEval = $scanService->getHealthScoreEvaluation($latestScan);
        $totalBytes = $scanService->getTotalAssetBytes();

        $this->stdout("Latest Scan ID:        #{$latestScan->id}\n");
        $this->stdout("Scan Timestamp:        {$latestScan->dateCreated}\n");
        $this->stdout("Health Score:          {$latestScan->healthScore}/100 [{$healthEval['label']}]\n", Console::FG_GREEN, Console::BOLD);
        $this->stdout("Total Assets Scanned:  {$latestScan->totalAssets}\n");
        $this->stdout("Total Disk Storage:    " . $scanService->formatBytes($totalBytes) . "\n");
        $this->stdout("------------------------------------------\n");
        $this->stdout("Unused Assets:         {$latestScan->unusedCount}\n", $latestScan->unusedCount > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("Duplicate Clones:      {$latestScan->duplicateCount}\n", $latestScan->duplicateCount > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("Large Files:           {$latestScan->largeFileCount}\n");
        $this->stdout("Missing Alt Text:      {$latestScan->missingAltCount}\n", $latestScan->missingAltCount > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("------------------------------------------\n");
        $this->stdout("Recoverable Space:     " . $scanService->formatBytes((int)$latestScan->potentialRecovery) . "\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout("==========================================\n\n");

        return ExitCode::OK;
    }
}
