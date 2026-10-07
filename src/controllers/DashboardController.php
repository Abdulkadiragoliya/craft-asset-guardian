<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * Gathers and presents asset health metrics, score calculations,
 * storage breakdowns, and needs-attention items.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class DashboardController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewDashboard';

    /**
     * Display the main Asset Guardian dashboard
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $settings = AssetGuardian::getInstance()->getSettings();

        $latestCompletedScan = $scanService->getLatestCompletedScan();
        $runningScan = $scanService->getRunningScan();
        $latestScan = $scanService->getLatestScan();

        $isScanRunning = ($runningScan !== null);
        $lastScanFailed = ($latestScan !== null && $latestScan->status === 'failed');

        $totalAssetCount = $scanService->getTotalAssetCount();
        $totalAssetBytes = $scanService->getTotalAssetBytes();
        $formattedTotalStorage = $scanService->formatBytes($totalAssetBytes);

        $storageByVolume = $scanService->getStorageByVolume();
        $storageByKind = $scanService->getStorageByKind();

        $health = $scanService->getHealthScoreEvaluation($latestCompletedScan);

        // Stats from scan or library defaults
        $stats = [
            'totalAssets' => $latestCompletedScan ? $latestCompletedScan->totalAssets : $totalAssetCount,
            'totalStorage' => $formattedTotalStorage,
            'unusedCount' => $latestCompletedScan ? $latestCompletedScan->unusedCount : 0,
            'duplicateCount' => $latestCompletedScan ? $latestCompletedScan->duplicateCount : 0,
            'largeFileCount' => $latestCompletedScan ? $latestCompletedScan->largeFileCount : 0,
            'missingAltCount' => $latestCompletedScan ? $latestCompletedScan->missingAltCount : 0,
            'recoverableBytes' => $latestCompletedScan ? $latestCompletedScan->potentialRecovery : 0,
            'formattedRecoverable' => $latestCompletedScan ? $scanService->formatBytes($latestCompletedScan->potentialRecovery) : '0 B',
        ];

        // Format large file threshold label
        $thresholdLabel = $scanService->formatBytes($settings->largeFileThresholdBytes, 0);

        // Duration string
        $duration = $latestCompletedScan ? $scanService->formatDuration($latestCompletedScan->startedAt, $latestCompletedScan->completedAt) : '—';

        return $this->renderCpTemplate('asset-guardian/dashboard/index', [
            'selectedSubnavItem' => 'dashboard',
            'title' => Craft::t('asset-guardian', 'Dashboard'),
            'latestScan' => $latestCompletedScan,
            'runningScan' => $runningScan,
            'isScanRunning' => $isScanRunning,
            'lastScanFailed' => $lastScanFailed,
            'failedScan' => $lastScanFailed ? $latestScan : null,
            'health' => $health,
            'stats' => $stats,
            'thresholdLabel' => $thresholdLabel,
            'duration' => $duration,
            'storageByVolume' => $storageByVolume,
            'storageByKind' => $storageByKind,
        ]);
    }

    /**
     * Triggers a new library scan
     *
     * @return Response
     */
    public function actionScan(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-runScans');

        $userId = Craft::$app->getUser()->getId();
        AssetGuardian::getInstance()->scan->startScan($userId);

        $this->setSuccessFlash(Craft::t('asset-guardian', 'Asset Guardian scan started.'));

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('asset-guardian', 'Asset Guardian scan started.'),
            ]);
        }

        return $this->redirectToPostedUrl(null, 'asset-guardian/dashboard');
    }

    /**
     * Checks whether an asset scan is currently running
     *
     * @return Response
     */
    public function actionStatus(): Response
    {
        $runningScan = AssetGuardian::getInstance()->scan->getRunningScan();
        return $this->asJson([
            'running' => ($runningScan !== null),
            'scanId' => $runningScan ? (int)$runningScan->id : null,
        ]);
    }
}
