<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\console\ExitCode;

/**
 * CLI Scan Controller for Asset Guardian
 *
 * Usage:
 *   php craft asset-guardian/scan
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\console\controllers
 * @since 1.0.0
 */
class ScanController extends Controller
{
    /**
     * Executes a full library scan directly from the terminal
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $this->stdout("\n--- Asset Guardian Library Scan ---\n", Console::FG_CYAN, Console::BOLD);

        $scanService = AssetGuardian::getInstance()->scan;
        $totalAssets = $scanService->getTotalAssetCount();

        $this->stdout("Total indexed assets: {$totalAssets}\n");
        $this->stdout("Starting library scan...\n");

        try {
            $scan = $scanService->startScan();
            $this->stdout("Executing scan analysis (Scan #{$scan->id})...\n");

            // Execute scan synchronously in CLI context
            $scanService->executeScan((int)$scan->id);

            // Fetch refreshed scan record
            $completedScan = \abdulkadiragoliya\assetguardian\records\ScanRecord::findOne($scan->id);
            $healthEval = $scanService->getHealthScoreEvaluation($completedScan);

            $this->stdout("\n=== Scan Completed Successfully! ===\n", Console::FG_GREEN, Console::BOLD);
            $this->stdout("Health Score: {$completedScan->healthScore}/100 ({$healthEval['label']})\n");
            $this->stdout("Unused Assets: {$completedScan->unusedCount}\n");
            $this->stdout("Duplicate Clones: {$completedScan->duplicateCount}\n");
            $this->stdout("Large Files: {$completedScan->largeFileCount}\n");
            $this->stdout("Missing Alt Text: {$completedScan->missingAltCount}\n");
            $this->stdout("Potential Reclaimed Space: " . $scanService->formatBytes((int)$completedScan->potentialRecovery) . "\n\n");

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("\nError during scan: " . $e->getMessage() . "\n", Console::FG_RED, Console::BOLD);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
