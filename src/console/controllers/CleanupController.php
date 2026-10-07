<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\console\ExitCode;

/**
 * CLI Cleanup Controller for Asset Guardian
 *
 * Usage:
 *   php craft asset-guardian/cleanup --dry-run
 *   php craft asset-guardian/cleanup --safe-only --yes
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\console\controllers
 * @since 1.0.0
 */
class CleanupController extends Controller
{
    /**
     * @var bool Whether to run a simulation without performing deletions
     */
    public bool $dryRun = false;

    /**
     * @var bool Whether to restrict cleanup strictly to low-risk unreferenced assets
     */
    public bool $safeOnly = true;

    /**
     * @var bool Whether to automatically confirm without terminal prompts
     */
    public bool $yes = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'safeOnly',
            'yes',
        ]);
    }

    /**
     * Executes safe cleanup from the command line
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $cleanupService = AssetGuardian::getInstance()->cleanup;

        $latestScan = $scanService->getLatestCompletedScan();
        if (!$latestScan) {
            $this->stderr("No completed scan found. Run 'php craft asset-guardian/scan' first.\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $query = (new Query())
            ->select(['assetId', 'risk', 'size'])
            ->from(Table::FINDINGS)
            ->where(['scanId' => (int)$latestScan->id])
            ->andWhere(['type' => ['unused', 'duplicate']]);

        if ($this->safeOnly) {
            $query->andWhere(['risk' => 'low']);
        }

        $findings = $query->all();
        if (empty($findings)) {
            $this->stdout("No matching cleanup candidates found.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $assetIds = array_map(fn($f) => (int)$f['assetId'], $findings);
        $totalBytes = array_sum(array_column($findings, 'size'));
        $count = count($assetIds);
        $formattedBytes = $scanService->formatBytes($totalBytes);

        $this->stdout("\n--- Asset Guardian CLI Cleanup ---\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout("Candidates identified: {$count} assets ({$formattedBytes})\n");
        $this->stdout("Filter mode:           " . ($this->safeOnly ? "Safe Only (Low Risk)" : "All Identified Candidates") . "\n");

        if ($this->dryRun) {
            $this->stdout("\n[DRY RUN MODE ENABLED] Simulating cleanup...\n", Console::FG_YELLOW, Console::BOLD);
            $simulation = $cleanupService->simulateCleanup($assetIds);
            $this->stdout("Safe to trash:         {$simulation['safeCount']}\n");
            $this->stdout("Warnings:              {$simulation['warningCount']}\n");
            $this->stdout("Recoverable Storage:   {$simulation['formattedBytes']}\n\n");
            $this->stdout("Simulation complete. No files were modified.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        if (!$this->yes) {
            if (!$this->confirm("Are you sure you want to move {$count} assets ({$formattedBytes}) to Craft Trash?")) {
                $this->stdout("Aborted by user.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        try {
            $result = $cleanupService->executeCleanup($assetIds, null, false);
            $this->stdout("\nSuccess! Moved {$result['assetCount']} assets ({$result['formattedBytes']}) to Craft Trash.\n", Console::FG_GREEN, Console::BOLD);
            $this->stdout("Operation audit ID: #{$result['operationId']}\n\n");
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Error during cleanup: " . $e->getMessage() . "\n", Console::FG_RED, Console::BOLD);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
