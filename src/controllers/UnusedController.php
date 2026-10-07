<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Json;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Unused Assets Controller
 *
 * Provides sortable, filterable inspection of unreferenced assets,
 * detailed risk analysis breakdown, and reference tracing.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class UnusedController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewUnusedAssets';

    /**
     * Display Unused Assets review list with filters and server-side pagination
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $latestScan = $scanService->getLatestCompletedScan();

        $request = Craft::$app->getRequest();
        $volumeFilter = $request->getQueryParam('volume');
        $riskFilter = $request->getQueryParam('risk');
        $kindFilter = $request->getQueryParam('kind');
        $searchFilter = trim((string)$request->getQueryParam('search', ''));
        $sortFilter = $request->getQueryParam('sort', 'size_desc');
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = 25;

        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $totalUnusedCount = 0;
        $totalPotentialBytes = 0;
        $items = [];
        $totalPages = 1;

        if ($latestScan !== null) {
            $totalUnusedCount = $latestScan->unusedCount;

            // Base query for unused findings in the latest scan
            $query = (new Query())
                ->select([
                    'f.id as findingId',
                    'f.assetId',
                    'f.risk',
                    'f.reason',
                    'f.metadata',
                    'a.filename',
                    'a.size',
                    'a.kind',
                    'a.volumeId',
                    'a.dateModified',
                    'e.dateCreated',
                    'v.name as volumeName',
                    'v.handle as volumeHandle',
                ])
                ->from(['f' => Table::FINDINGS])
                ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[f.assetId]]')
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
                ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
                ->where(['f.scanId' => $latestScan->id, 'f.type' => 'unused']);

            // Total potential storage for unused assets in this scan
            $totalPotentialBytes = (int)((clone $query)->sum('a.size') ?: 0);

            // Apply filters
            if (!empty($volumeFilter)) {
                $query->andWhere(['v.handle' => $volumeFilter]);
            }

            if (!empty($riskFilter)) {
                $query->andWhere(['f.risk' => $riskFilter]);
            }

            if (!empty($kindFilter)) {
                $query->andWhere(['a.kind' => $kindFilter]);
            }

            if (!empty($searchFilter)) {
                $query->andWhere(['like', 'a.filename', $searchFilter]);
            }

            // Apply sorting
            match ($sortFilter) {
                'size_asc' => $query->orderBy(['a.size' => SORT_ASC]),
                'date_desc' => $query->orderBy(['a.dateModified' => SORT_DESC]),
                'date_asc' => $query->orderBy(['a.dateModified' => SORT_ASC]),
                'name_asc' => $query->orderBy(['a.filename' => SORT_ASC]),
                default => $query->orderBy(['a.size' => SORT_DESC]),
            };

            // Count filtered rows
            $filteredCount = (int)(clone $query)->count();
            $totalPages = max(1, (int)ceil($filteredCount / $limit));
            $offset = ($page - 1) * $limit;

            $rows = $query->limit($limit)->offset($offset)->all();

            foreach ($rows as $row) {
                $items[] = [
                    'findingId' => (int)$row['findingId'],
                    'assetId' => (int)$row['assetId'],
                    'filename' => $row['filename'],
                    'size' => (int)$row['size'],
                    'formattedSize' => $scanService->formatBytes((int)$row['size']),
                    'kind' => $row['kind'],
                    'volumeName' => $row['volumeName'] ?? '—',
                    'volumeHandle' => $row['volumeHandle'] ?? '',
                    'dateModified' => $row['dateModified'],
                    'dateCreated' => $row['dateCreated'],
                    'risk' => $row['risk'],
                    'reason' => $row['reason'],
                    'metadata' => $row['metadata'] ? Json::decodeIfJson($row['metadata']) : [],
                ];
            }
        }

        return $this->renderCpTemplate('asset-guardian/unused/index', [
            'selectedSubnavItem' => 'unused',
            'title' => Craft::t('asset-guardian', 'Unused Assets'),
            'latestScan' => $latestScan,
            'items' => $items,
            'totalUnusedCount' => $totalUnusedCount,
            'formattedPotentialBytes' => $scanService->formatBytes($totalPotentialBytes),
            'volumes' => $volumes,
            'currentFilters' => [
                'volume' => $volumeFilter,
                'risk' => $riskFilter,
                'kind' => $kindFilter,
                'search' => $searchFilter,
                'sort' => $sortFilter,
                'page' => $page,
            ],
            'pagination' => [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $filteredCount ?? 0,
            ],
        ]);
    }

    /**
     * Detail analysis endpoint for a single asset
     *
     * @param int $assetId
     * @return Response
     */
    public function actionDetail(int $assetId): Response
    {
        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        $usageData = $usageService->getUsageForAssetIds([$assetId]);
        $usageInfo = $usageData[$assetId] ?? ['count' => 0, 'sources' => []];

        $risk = $usageService->determineRisk($usageInfo['count'], $asset->dateCreated?->format('Y-m-d H:i:s'));

        // Resolve reference element titles
        $resolvedSources = [];
        foreach ($usageInfo['sources'] as $source) {
            $element = Craft::$app->getElements()->getElementById($source['sourceId']);
            $resolvedSources[] = [
                'id' => $source['sourceId'],
                'type' => $source['type'],
                'title' => $element ? (string)$element : 'Element #' . $source['sourceId'],
                'cpEditUrl' => $element?->getCpEditUrl(),
            ];
        }

        $recommendation = match ($risk) {
            'low' => Craft::t('asset-guardian', 'Safe to review for removal. No supported references found.'),
            'medium' => Craft::t('asset-guardian', 'Review required. No standard relations detected, but uploaded recently.'),
            default => Craft::t('asset-guardian', 'In use. Known content relationships exist. Do not delete.'),
        };

        return $this->asJson([
            'success' => true,
            'asset' => [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'size' => (int)$asset->size,
                'formattedSize' => $scanService->formatBytes((int)$asset->size),
                'kind' => $asset->kind,
                'width' => $asset->width,
                'height' => $asset->height,
                'mimeType' => $asset->mimeType,
                'volumeName' => $asset->getVolume()?->name ?? '—',
                'dateModified' => $asset->dateModified?->format('Y-m-d H:i:s'),
                'dateCreated' => $asset->dateCreated?->format('Y-m-d H:i:s'),
                'url' => $asset->getUrl(),
                'cpEditUrl' => $asset->getCpEditUrl(),
            ],
            'usage' => [
                'referenceCount' => $usageInfo['count'],
                'sources' => $resolvedSources,
            ],
            'risk' => $risk,
            'recommendation' => $recommendation,
        ]);
    }
}
