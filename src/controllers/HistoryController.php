<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\web\Response;

/**
 * Scan & Cleanup History Controller
 *
 * Provides historical audit trails of past asset library scans and
 * executed cleanup operations with one-click restore capabilities.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class HistoryController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewHistory';

    /**
     * Display historical scans and cleanup operations audit log
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $request = Craft::$app->getRequest();
        $tab = $request->getQueryParam('tab', 'scans');

        // 1. Past Scans
        $scansQuery = (new Query())
            ->from(Table::SCANS)
            ->orderBy(['id' => SORT_DESC])
            ->limit(50);

        $scanRows = $scansQuery->all();
        $scans = [];
        foreach ($scanRows as $row) {
            $duration = $scanService->formatDuration($row['startedAt'] ?? null, $row['completedAt'] ?? null);
            $scans[] = [
                'id' => (int)$row['id'],
                'status' => $row['status'],
                'startedAt' => $row['startedAt'],
                'completedAt' => $row['completedAt'],
                'duration' => $duration,
                'totalAssets' => (int)$row['totalAssets'],
                'unusedCount' => (int)$row['unusedCount'],
                'duplicateCount' => (int)$row['duplicateCount'],
                'largeFileCount' => (int)$row['largeFileCount'],
                'missingAltCount' => (int)$row['missingAltCount'],
                'healthScore' => (int)$row['healthScore'],
                'formattedRecovery' => $scanService->formatBytes((int)$row['potentialRecovery']),
                'errorMessage' => $row['errorMessage'],
                'dateCreated' => $row['dateCreated'],
            ];
        }

        // 2. Past Cleanup Operations
        $cleanupRows = (new Query())
            ->select([
                'co.*',
                'u.username',
                'u.email',
                'u.firstName',
                'u.lastName',
            ])
            ->from(['co' => Table::CLEANUP_OPERATIONS])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[co.userId]]')
            ->orderBy(['co.id' => SORT_DESC])
            ->limit(50)
            ->all();

        $cleanups = [];
        foreach ($cleanupRows as $crow) {
            $userLabel = trim(($crow['firstName'] ?? '') . ' ' . ($crow['lastName'] ?? ''));
            if ($userLabel === '') {
                $userLabel = $crow['username'] ?? $crow['email'] ?? Craft::t('asset-guardian', 'System / CLI');
            }

            $meta = json_decode($crow['metadata'] ?? '{}', true);

            $cleanups[] = [
                'id' => (int)$crow['id'],
                'dateCreated' => $crow['dateCreated'],
                'user' => $userLabel,
                'status' => $crow['status'],
                'assetCount' => (int)$crow['assetCount'],
                'totalBytes' => (int)$crow['totalBytes'],
                'formattedBytes' => $scanService->formatBytes((int)$crow['totalBytes']),
                'operation' => $crow['operation'],
                'isSoftDelete' => ($crow['operation'] === 'soft_delete'),
                'canRestore' => ($crow['status'] === 'completed' && $crow['operation'] === 'soft_delete'),
                'assetIds' => $meta['assetIds'] ?? [],
            ];
        }

        return $this->renderCpTemplate('asset-guardian/history/index', [
            'selectedSubnavItem' => 'history',
            'title' => Craft::t('asset-guardian', 'Scan & Audit History'),
            'activeTab' => $tab,
            'scans' => $scans,
            'cleanups' => $cleanups,
        ]);
    }
}
