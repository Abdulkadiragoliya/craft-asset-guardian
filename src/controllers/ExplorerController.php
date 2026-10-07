<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\web\Response;

/**
 * Asset Explorer Controller
 *
 * Provides a deep multi-faceted inspection browser across all indexed assets,
 * supporting multi-attribute filtering (volume, kind, usage, risk, duplicate, alt text),
 * instant searching, and quick links to Craft CP asset management.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class ExplorerController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewAssetExplorer';

    /**
     * Display the Asset Explorer
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        $request = Craft::$app->getRequest();
        $volumeFilter = $request->getQueryParam('volume');
        $kindFilter = $request->getQueryParam('kind');
        $usageFilter = $request->getQueryParam('usage');
        $riskFilter = $request->getQueryParam('risk');
        $altFilter = $request->getQueryParam('alt');
        $duplicateFilter = $request->getQueryParam('duplicate');
        $searchFilter = trim((string)$request->getQueryParam('search', ''));
        $sortFilter = $request->getQueryParam('sort', 'date_desc');
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = 50;

        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        // Base Asset Query
        $query = (new Query())
            ->select([
                'a.id as assetId',
                'a.filename',
                'a.size',
                'a.kind',
                'a.alt',
                'a.width',
                'a.height',
                'a.volumeId',
                'a.dateModified',
                'e.dateCreated',
                'v.name as volumeName',
                'v.handle as volumeHandle',
            ])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where(['e.dateDeleted' => null]);

        // Volume filter
        if (!empty($volumeFilter)) {
            $query->andWhere(['v.handle' => $volumeFilter]);
        }

        // Kind filter
        if (!empty($kindFilter)) {
            $query->andWhere(['a.kind' => $kindFilter]);
        }

        // Search filter
        if (!empty($searchFilter)) {
            $query->andWhere(['like', 'a.filename', $searchFilter]);
        }

        // Alt Text filter
        if ($altFilter === 'missing') {
            $query->andWhere(['a.kind' => 'image'])
                ->andWhere(['or', ['a.alt' => null], ['a.alt' => '']]);
        } elseif ($altFilter === 'present') {
            $query->andWhere(['a.kind' => 'image'])
                ->andWhere(['and', ['not', ['a.alt' => null]], ['not', ['a.alt' => '']]]);
        }

        // Duplicate filter (join against latest scan findings)
        $latestCompletedScan = $scanService->getLatestCompletedScan();
        $scanId = $latestCompletedScan ? (int)$latestCompletedScan->id : 0;

        if ($duplicateFilter === 'yes' && $scanId > 0) {
            $query->innerJoin(['dupFind' => Table::FINDINGS], '[[dupFind.assetId]] = [[a.id]] AND [[dupFind.scanId]] = :scanId AND [[dupFind.type]] = :dupType', [
                ':scanId' => $scanId,
                ':dupType' => 'duplicate',
            ]);
        } elseif ($duplicateFilter === 'no' && $scanId > 0) {
            $query->andWhere([
                'not in',
                'a.id',
                (new Query())
                    ->select(['assetId'])
                    ->from(Table::FINDINGS)
                    ->where(['scanId' => $scanId, 'type' => 'duplicate'])
            ]);
        }

        // Usage filter
        if ($usageFilter === 'unused' && $scanId > 0) {
            $query->innerJoin(['unusedFind' => Table::FINDINGS], '[[unusedFind.assetId]] = [[a.id]] AND [[unusedFind.scanId]] = :scanId AND [[unusedFind.type]] = :unusedType', [
                ':scanId' => $scanId,
                ':unusedType' => 'unused',
            ]);
        } elseif ($usageFilter === 'used' && $scanId > 0) {
            $query->andWhere([
                'not in',
                'a.id',
                (new Query())
                    ->select(['assetId'])
                    ->from(Table::FINDINGS)
                    ->where(['scanId' => $scanId, 'type' => 'unused'])
            ]);
        }

        // Risk filter
        if (!empty($riskFilter) && $scanId > 0) {
            $query->innerJoin(['riskFind' => Table::FINDINGS], '[[riskFind.assetId]] = [[a.id]] AND [[riskFind.scanId]] = :scanId AND [[riskFind.risk]] = :riskLevel', [
                ':scanId' => $scanId,
                ':riskLevel' => $riskFilter,
            ]);
        }

        // Sorting
        match ($sortFilter) {
            'size_desc' => $query->orderBy(['a.size' => SORT_DESC]),
            'size_asc' => $query->orderBy(['a.size' => SORT_ASC]),
            'date_asc' => $query->orderBy(['a.dateModified' => SORT_ASC]),
            'name_asc' => $query->orderBy(['a.filename' => SORT_ASC]),
            'name_desc' => $query->orderBy(['a.filename' => SORT_DESC]),
            default => $query->orderBy(['a.dateModified' => SORT_DESC]),
        };

        $totalCount = (int)(clone $query)->count();
        $totalBytes = (int)((clone $query)->sum('a.size') ?: 0);
        $totalPages = max(1, (int)ceil($totalCount / $limit));
        $offset = ($page - 1) * $limit;

        $rows = $query->offset($offset)->limit($limit)->all();
        $assetIds = array_map(fn($r) => (int)$r['assetId'], $rows);

        // Fetch usage data for current page items
        $usageData = $usageService->getUsageForAssetIds($assetIds);

        // Fetch findings for current page items from latest scan
        $findingsMap = [];
        if ($scanId > 0 && !empty($assetIds)) {
            $findings = (new Query())
                ->from(Table::FINDINGS)
                ->where(['scanId' => $scanId, 'assetId' => $assetIds])
                ->all();

            foreach ($findings as $f) {
                $findingsMap[(int)$f['assetId']][] = $f;
            }
        }

        // Fetch Asset Elements for thumbnail URLs
        $assetElements = !empty($assetIds)
            ? Asset::find()->id($assetIds)->all()
            : [];
        $assetElementMap = [];
        foreach ($assetElements as $asset) {
            $assetElementMap[$asset->id] = $asset;
        }

        $items = [];
        foreach ($rows as $row) {
            $assetId = (int)$row['assetId'];
            $usageInfo = $usageData[$assetId] ?? ['count' => 0, 'sources' => []];
            $assetFindings = $findingsMap[$assetId] ?? [];
            $assetModel = $assetElementMap[$assetId] ?? null;

            $thumbUrl = null;
            if ($assetModel && $row['kind'] === 'image') {
                try {
                    $thumbUrl = $assetModel->getUrl(['width' => 80, 'height' => 80, 'mode' => 'crop']) ?? $assetModel->getUrl();
                } catch (\Throwable $e) {
                    $thumbUrl = null;
                }
            }

            // Determine primary risk from findings or calculate default
            $maxRisk = 'low';
            $isDuplicate = false;
            $isUnused = false;
            foreach ($assetFindings as $f) {
                if ($f['type'] === 'duplicate') {
                    $isDuplicate = true;
                }
                if ($f['type'] === 'unused') {
                    $isUnused = true;
                }
                if ($f['risk'] === 'high') {
                    $maxRisk = 'high';
                } elseif ($f['risk'] === 'medium' && $maxRisk !== 'high') {
                    $maxRisk = 'medium';
                }
            }

            $dimensions = ($row['width'] && $row['height'])
                ? "{$row['width']} × {$row['height']} px"
                : null;

            $editUrl = $assetModel?->getCpEditUrl() ?? \craft\helpers\UrlHelper::cpUrl("assets/{$row['volumeHandle']}/{$assetId}");

            $items[] = [
                'assetId' => $assetId,
                'filename' => $row['filename'],
                'kind' => $row['kind'],
                'size' => (int)$row['size'],
                'formattedSize' => $scanService->formatBytes((int)$row['size']),
                'dimensions' => $dimensions,
                'volumeName' => $row['volumeName'] ?? '—',
                'volumeHandle' => $row['volumeHandle'] ?? '',
                'dateCreated' => $row['dateCreated'],
                'dateModified' => $row['dateModified'],
                'alt' => trim((string)($row['alt'] ?? '')),
                'hasAlt' => !empty(trim((string)($row['alt'] ?? ''))),
                'usageCount' => $usageInfo['count'],
                'isUsed' => ($usageInfo['count'] > 0),
                'isDuplicate' => $isDuplicate,
                'isUnused' => $isUnused,
                'risk' => $maxRisk,
                'thumbUrl' => $thumbUrl,
                'editUrl' => $editUrl,
            ];
        }

        return $this->renderCpTemplate('asset-guardian/explorer/index', [
            'selectedSubnavItem' => 'explorer',
            'title' => Craft::t('asset-guardian', 'Asset Explorer'),
            'items' => $items,
            'totalCount' => $totalCount,
            'formattedTotalBytes' => $scanService->formatBytes($totalBytes),
            'volumes' => $volumes,
            'activeVolume' => $volumeFilter,
            'activeKind' => $kindFilter,
            'activeUsage' => $usageFilter,
            'activeRisk' => $riskFilter,
            'activeAlt' => $altFilter,
            'activeDuplicate' => $duplicateFilter,
            'activeSearch' => $searchFilter,
            'activeSort' => $sortFilter,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
