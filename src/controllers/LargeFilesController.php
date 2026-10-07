<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\web\Response;

/**
 * Large Files Controller
 *
 * Identifies oversized files exceeding configurable size thresholds,
 * displays storage footprints, and checks content relationships.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class LargeFilesController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewLargeFiles';

    /**
     * Display Large Files review table with threshold selector and filtering
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;
        $settings = AssetGuardian::getInstance()->getSettings();

        $request = Craft::$app->getRequest();
        $volumeFilter = $request->getQueryParam('volume');
        $kindFilter = $request->getQueryParam('kind');
        $searchFilter = trim((string)$request->getQueryParam('search', ''));
        $sortFilter = $request->getQueryParam('sort', 'size_desc');

        // Allow threshold override from URL, defaulting to settings threshold
        $thresholdParam = $request->getQueryParam('threshold');
        $activeThresholdBytes = $thresholdParam ? (int)$thresholdParam : $settings->largeFileThresholdBytes;

        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        // Query all assets exceeding active threshold
        $query = (new Query())
            ->select([
                'a.id as assetId',
                'a.filename',
                'a.size',
                'a.kind',
                'a.volumeId',
                'a.dateModified',
                'e.dateCreated',
                'v.name as volumeName',
                'v.handle as volumeHandle',
            ])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where(['>', 'a.size', $activeThresholdBytes])
            ->andWhere(['e.dateDeleted' => null]);

        if (!empty($volumeFilter)) {
            $query->andWhere(['v.handle' => $volumeFilter]);
        }

        if (!empty($kindFilter)) {
            $query->andWhere(['a.kind' => $kindFilter]);
        }

        if (!empty($searchFilter)) {
            $query->andWhere(['like', 'a.filename', $searchFilter]);
        }

        match ($sortFilter) {
            'size_asc' => $query->orderBy(['a.size' => SORT_ASC]),
            'date_desc' => $query->orderBy(['a.dateModified' => SORT_DESC]),
            'date_asc' => $query->orderBy(['a.dateModified' => SORT_ASC]),
            'name_asc' => $query->orderBy(['a.filename' => SORT_ASC]),
            default => $query->orderBy(['a.size' => SORT_DESC]),
        };

        $totalLargeCount = (int)(clone $query)->count();
        $totalLargeBytes = (int)((clone $query)->sum('a.size') ?: 0);

        // Limit results to 100 for fast page render
        $rows = $query->limit(100)->all();

        $assetIds = array_map(fn($r) => (int)$r['assetId'], $rows);
        $usageData = $usageService->getUsageForAssetIds($assetIds);

        $items = [];
        foreach ($rows as $row) {
            $id = (int)$row['assetId'];
            $usageInfo = $usageData[$id] ?? ['count' => 0];
            $count = $usageInfo['count'];
            $risk = $usageService->determineRisk($count, $row['dateCreated']);

            $items[] = [
                'assetId' => $id,
                'filename' => $row['filename'],
                'size' => (int)$row['size'],
                'formattedSize' => $scanService->formatBytes((int)$row['size']),
                'kind' => $row['kind'],
                'volumeName' => $row['volumeName'] ?? '—',
                'volumeHandle' => $row['volumeHandle'] ?? '',
                'dateModified' => $row['dateModified'] ?? $row['dateCreated'],
                'usageCount' => $count,
                'risk' => $risk,
            ];
        }

        $thresholdOptions = [
            1048576 => '1 MB',
            5242880 => '5 MB',
            10485760 => '10 MB',
            52428800 => '50 MB',
            104857600 => '100 MB',
        ];

        return $this->renderCpTemplate('asset-guardian/large-files/index', [
            'selectedSubnavItem' => 'large-files',
            'title' => Craft::t('asset-guardian', 'Large Files'),
            'items' => $items,
            'totalCount' => $totalLargeCount,
            'formattedTotalBytes' => $scanService->formatBytes($totalLargeBytes),
            'activeThresholdBytes' => $activeThresholdBytes,
            'activeThresholdLabel' => $scanService->formatBytes($activeThresholdBytes, 0),
            'thresholdOptions' => $thresholdOptions,
            'volumes' => $volumes,
            'currentFilters' => [
                'volume' => $volumeFilter,
                'kind' => $kindFilter,
                'search' => $searchFilter,
                'sort' => $sortFilter,
                'threshold' => $activeThresholdBytes,
            ],
        ]);
    }
}
