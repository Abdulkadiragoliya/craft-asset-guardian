<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Reports Controller
 *
 * Provides executive audit reports and CSV data exports for
 * library health, unused assets, duplicates, and accessibility compliance.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class ReportsController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-exportReports';

    /**
     * Display the Reports dashboard
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $latestScan = $scanService->getLatestCompletedScan();

        $stats = [
            'totalAssets' => $latestScan ? $latestScan->totalAssets : $scanService->getTotalAssetCount(),
            'totalStorage' => $scanService->formatBytes($scanService->getTotalAssetBytes()),
            'unusedCount' => $latestScan ? $latestScan->unusedCount : 0,
            'duplicateCount' => $latestScan ? $latestScan->duplicateCount : 0,
            'missingAltCount' => $latestScan ? $latestScan->missingAltCount : 0,
            'healthScore' => $latestScan ? $latestScan->healthScore : 100,
        ];

        return $this->renderCpTemplate('asset-guardian/reports/index', [
            'selectedSubnavItem' => 'reports',
            'title' => Craft::t('asset-guardian', 'Reports & Exports'),
            'latestScan' => $latestScan,
            'stats' => $stats,
        ]);
    }

    /**
     * Stream CSV download
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionDownload(): Response
    {
        $this->requirePermission('assetGuardian-exportReports');

        $request = Craft::$app->getRequest();
        $type = $request->getQueryParam('type');
        $reportService = AssetGuardian::getInstance()->report;

        $dateStamp = date('Y-m-d_His');

        switch ($type) {
            case 'health':
                $csv = $reportService->exportHealthAuditCsv();
                $filename = "asset-guardian-health-audit-{$dateStamp}.csv";
                break;

            case 'unused':
                $csv = $reportService->exportUnusedAssetsCsv();
                $filename = "asset-guardian-unused-assets-{$dateStamp}.csv";
                break;

            case 'duplicates':
                $csv = $reportService->exportDuplicatesCsv();
                $filename = "asset-guardian-duplicates-{$dateStamp}.csv";
                break;

            case 'missing-alt':
                $csv = $reportService->exportMissingAltCsv();
                $filename = "asset-guardian-missing-alt-text-{$dateStamp}.csv";
                break;

            default:
                throw new BadRequestHttpException("Unsupported report type '{$type}'.");
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $csv,
            $filename,
            ['mimeType' => 'text/csv; charset=UTF-8']
        );
    }
}
