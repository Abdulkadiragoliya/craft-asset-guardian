<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Missing Alt Text Controller
 *
 * Scans image assets lacking alternative text metadata, displays
 * compliance percentages, and provides inline quick-save capabilities
 * to safeguard site accessibility and SEO.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class MissingAltController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewMissingAltText';

    /**
     * Display Missing Alt Text review center
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        $request = Craft::$app->getRequest();
        $volumeFilter = $request->getQueryParam('volume');
        $searchFilter = trim((string)$request->getQueryParam('search', ''));
        $sortFilter = $request->getQueryParam('sort', 'date_desc');
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = 50;

        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        // 1. Overall stats across all images
        $totalImagesQuery = (new Query())
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['a.kind' => 'image'])
            ->andWhere(['e.dateDeleted' => null]);

        $totalImagesCount = (int)(clone $totalImagesQuery)->count();

        // 2. Query images missing alt text
        $missingQuery = (new Query())
            ->select([
                'a.id as assetId',
                'a.filename',
                'a.size',
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
            ->where(['a.kind' => 'image'])
            ->andWhere(['or', ['a.alt' => null], ['a.alt' => '']])
            ->andWhere(['e.dateDeleted' => null]);

        if (!empty($volumeFilter)) {
            $missingQuery->andWhere(['v.handle' => $volumeFilter]);
        }

        if (!empty($searchFilter)) {
            $missingQuery->andWhere(['like', 'a.filename', $searchFilter]);
        }

        match ($sortFilter) {
            'date_asc' => $missingQuery->orderBy(['a.dateModified' => SORT_ASC]),
            'name_asc' => $missingQuery->orderBy(['a.filename' => SORT_ASC]),
            'name_desc' => $missingQuery->orderBy(['a.filename' => SORT_DESC]),
            'size_desc' => $missingQuery->orderBy(['a.size' => SORT_DESC]),
            'size_asc' => $missingQuery->orderBy(['a.size' => SORT_ASC]),
            default => $missingQuery->orderBy(['a.dateModified' => SORT_DESC]),
        };

        $totalFilteredMissing = (int)(clone $missingQuery)->count();
        $totalPages = max(1, (int)ceil($totalFilteredMissing / $limit));
        $offset = ($page - 1) * $limit;

        $rows = $missingQuery->offset($offset)->limit($limit)->all();

        // Global missing count for overall compliance metric
        $globalMissingCount = (int)(new Query())
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['a.kind' => 'image'])
            ->andWhere(['or', ['a.alt' => null], ['a.alt' => '']])
            ->andWhere(['e.dateDeleted' => null])
            ->count();

        $complianceRate = $totalImagesCount > 0
            ? max(0, min(100, (int)round((($totalImagesCount - $globalMissingCount) / $totalImagesCount) * 100)))
            : 100;

        $assetIds = array_map(fn($r) => (int)$r['assetId'], $rows);
        $usageData = $usageService->getUsageForAssetIds($assetIds);

        // Fetch element models for thumb URL generation
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
            $assetModel = $assetElementMap[$assetId] ?? null;

            $thumbUrl = null;
            $editUrl = $assetModel?->getCpEditUrl() ?? \craft\helpers\UrlHelper::cpUrl("assets/{$row['volumeHandle']}/{$assetId}");

            if ($assetModel) {
                try {
                    $thumbUrl = $assetModel->getUrl(['width' => 80, 'height' => 80, 'mode' => 'crop']) ?? $assetModel->getUrl();
                } catch (\Throwable $e) {
                    $thumbUrl = null;
                }
            }

            $dimensions = ($row['width'] && $row['height'])
                ? "{$row['width']} × {$row['height']} px"
                : '—';

            $items[] = [
                'assetId' => $assetId,
                'filename' => $row['filename'],
                'size' => (int)$row['size'],
                'formattedSize' => $scanService->formatBytes((int)$row['size']),
                'dimensions' => $dimensions,
                'volumeName' => $row['volumeName'] ?? '—',
                'volumeHandle' => $row['volumeHandle'] ?? '',
                'dateCreated' => $row['dateCreated'],
                'usageCount' => $usageInfo['count'],
                'isUsed' => ($usageInfo['count'] > 0),
                'thumbUrl' => $thumbUrl,
                'editUrl' => $editUrl,
            ];
        }

        return $this->renderCpTemplate('asset-guardian/missing-alt/index', [
            'selectedSubnavItem' => 'missing-alt',
            'title' => Craft::t('asset-guardian', 'Missing Alt Text'),
            'items' => $items,
            'totalImagesCount' => $totalImagesCount,
            'globalMissingCount' => $globalMissingCount,
            'totalFilteredMissing' => $totalFilteredMissing,
            'complianceRate' => $complianceRate,
            'volumes' => $volumes,
            'activeVolume' => $volumeFilter,
            'activeSearch' => $searchFilter,
            'activeSort' => $sortFilter,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * Inline quick-save alternative text for an asset
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSaveAlt(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-manageAltText');

        $request = Craft::$app->getRequest();
        $assetId = (int)$request->getRequiredBodyParam('assetId');
        $alt = trim((string)$request->getBodyParam('alt', ''));

        if ($alt === '') {
            throw new BadRequestHttpException('Alt text cannot be empty.');
        }

        $asset = Asset::findOne($assetId);
        if (!$asset) {
            throw new BadRequestHttpException("Asset #{$assetId} not found.");
        }

        $asset->alt = $alt;
        if (!Craft::$app->getElements()->saveElement($asset)) {
            $errors = implode(', ', $asset->getFirstErrors());
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('asset-guardian', 'Failed to save alt text: {errors}', ['errors' => $errors]),
                ]);
            }
            $this->setFailFlash(Craft::t('asset-guardian', 'Failed to save alt text: {errors}', ['errors' => $errors]));
            return $this->redirectToPostedUrl(null, 'asset-guardian/missing-alt');
        }

        // Clean up any missing-alt findings for this asset
        Craft::$app->getDb()->createCommand()
            ->delete(Table::FINDINGS, [
                'assetId' => $assetId,
                'type' => 'missing-alt',
            ])
            ->execute();

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('asset-guardian', 'Alt text saved successfully.'),
                'assetId' => $assetId,
                'alt' => $alt,
            ]);
        }

        $this->setSuccessFlash(Craft::t('asset-guardian', 'Alt text saved successfully.'));
        return $this->redirectToPostedUrl(null, 'asset-guardian/missing-alt');
    }
}
