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
 * Cleanup Center Controller
 *
 * Implements safe, auditable bulk cleanup workflows.
 * Candidates are strictly evaluated by risk rating. High-risk items
 * are excluded from bulk selections by default. Deletions move assets
 * to Craft Trash (soft delete).
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class CleanupController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-manageCleanup';

    /**
     * Display the Cleanup Center dashboard and candidates
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        $latestScan = $scanService->getLatestCompletedScan();
        $scanId = $latestScan ? (int)$latestScan->id : 0;

        $candidates = [];
        $totalCandidateBytes = 0;
        $safeBytes = 0;
        $warningBytes = 0;
        $safeCount = 0;
        $warningCount = 0;

        if ($scanId > 0) {
            // Find recoverable candidates (unused assets and duplicate clones)
            $findings = (new Query())
                ->select([
                    'f.id as findingId',
                    'f.assetId',
                    'f.type',
                    'f.risk',
                    'f.size',
                    'f.reason',
                    'f.metadata',
                    'a.filename',
                    'a.kind',
                    'a.width',
                    'a.height',
                    'v.name as volumeName',
                    'v.handle as volumeHandle',
                    'e.dateCreated',
                ])
                ->from(['f' => Table::FINDINGS])
                ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[f.assetId]]')
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
                ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
                ->where(['f.scanId' => $scanId])
                ->andWhere(['f.type' => ['unused', 'duplicate']])
                ->andWhere(['e.dateDeleted' => null])
                ->orderBy(['f.size' => SORT_DESC])
                ->all();

            $assetIds = array_map(fn($r) => (int)$r['assetId'], $findings);
            $usageData = $usageService->getUsageForAssetIds($assetIds);

            // Fetch elements for thumbnails
            $assetElements = !empty($assetIds)
                ? Asset::find()->id($assetIds)->all()
                : [];
            $assetElementMap = [];
            foreach ($assetElements as $asset) {
                $assetElementMap[$asset->id] = $asset;
            }

            foreach ($findings as $row) {
                $assetId = (int)$row['assetId'];
                $size = (int)$row['size'];
                $totalCandidateBytes += $size;
                $usageInfo = $usageData[$assetId] ?? ['count' => 0, 'sources' => []];
                $usageCount = $usageInfo['count'];
                $risk = $row['risk'];

                $isSafe = ($risk === 'low' && $usageCount === 0);
                if ($isSafe) {
                    $safeCount++;
                    $safeBytes += $size;
                } else {
                    $warningCount++;
                    $warningBytes += $size;
                }

                $assetModel = $assetElementMap[$assetId] ?? null;
                $thumbUrl = null;
                if ($assetModel && $row['kind'] === 'image') {
                    try {
                        $thumbUrl = $assetModel->getUrl(['width' => 60, 'height' => 60, 'mode' => 'crop']) ?? $assetModel->getUrl();
                    } catch (\Throwable $e) {
                        $thumbUrl = null;
                    }
                }

                $typeLabel = $row['type'] === 'duplicate'
                    ? Craft::t('asset-guardian', 'Duplicate Clone')
                    : Craft::t('asset-guardian', 'Unused Asset');

                $editUrl = $assetModel?->getCpEditUrl() ?? \craft\helpers\UrlHelper::cpUrl("assets/{$row['volumeHandle']}/{$assetId}");

                $candidates[] = [
                    'assetId' => $assetId,
                    'findingId' => (int)$row['findingId'],
                    'filename' => $row['filename'],
                    'size' => $size,
                    'formattedSize' => $scanService->formatBytes($size),
                    'volumeName' => $row['volumeName'] ?? '—',
                    'kind' => $row['kind'],
                    'type' => $row['type'],
                    'typeLabel' => $typeLabel,
                    'risk' => $risk,
                    'isSafe' => $isSafe,
                    'reason' => $row['reason'],
                    'usageCount' => $usageCount,
                    'thumbUrl' => $thumbUrl,
                    'editUrl' => $editUrl,
                    'dateCreated' => $row['dateCreated'],
                ];
            }
        }

        return $this->renderCpTemplate('asset-guardian/cleanup/index', [
            'selectedSubnavItem' => 'cleanup',
            'title' => Craft::t('asset-guardian', 'Cleanup Center'),
            'latestScan' => $latestScan,
            'candidates' => $candidates,
            'totalCandidatesCount' => count($candidates),
            'formattedTotalBytes' => $scanService->formatBytes($totalCandidateBytes),
            'safeCount' => $safeCount,
            'formattedSafeBytes' => $scanService->formatBytes($safeBytes),
            'warningCount' => $warningCount,
            'formattedWarningBytes' => $scanService->formatBytes($warningBytes),
        ]);
    }

    /**
     * Dry Run simulation for selected assets
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSimulate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-manageCleanup');

        $request = Craft::$app->getRequest();
        $assetIds = $request->getBodyParam('assetIds', []);

        if (empty($assetIds)) {
            throw new BadRequestHttpException('No assets provided for simulation.');
        }

        $assetIds = array_map('intval', (array)$assetIds);
        $result = AssetGuardian::getInstance()->cleanup->simulateCleanup($assetIds);

        return $this->asJson([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Execute safe move-to-trash cleanup
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionExecute(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-manageCleanup');

        $request = Craft::$app->getRequest();
        $assetIds = $request->getBodyParam('assetIds', []);

        if (empty($assetIds)) {
            throw new BadRequestHttpException('No assets selected for cleanup.');
        }

        $assetIds = array_map('intval', (array)$assetIds);
        $userId = Craft::$app->getUser()->getId();

        try {
            $result = AssetGuardian::getInstance()->cleanup->executeCleanup($assetIds, $userId, false);

            $message = Craft::t('asset-guardian', 'Successfully moved {count} assets ({bytes}) to Craft Trash.', [
                'count' => $result['assetCount'],
                'bytes' => $result['formattedBytes'],
            ]);

            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'message' => $message,
                    'operationId' => $result['operationId'],
                ]);
            }

            $this->setSuccessFlash($message);
            return $this->redirectToPostedUrl(null, 'asset-guardian/cleanup');
        } catch (\Throwable $e) {
            Craft::error("Asset Guardian: Cleanup execution error: " . $e->getMessage(), __METHOD__);

            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }

            $this->setFailFlash($e->getMessage());
            return $this->redirectToPostedUrl(null, 'asset-guardian/cleanup');
        }
    }

    /**
     * Restore assets from a previous cleanup operation
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-restoreCleanup');

        $request = Craft::$app->getRequest();
        $operationId = (int)$request->getRequiredBodyParam('operationId');

        try {
            $result = AssetGuardian::getInstance()->cleanup->restoreOperation($operationId);

            if ($request->getAcceptsJson()) {
                return $this->asJson($result);
            }

            if ($result['success']) {
                $this->setSuccessFlash($result['message']);
            } else {
                $this->setFailFlash($result['message']);
            }

            return $this->redirectToPostedUrl(null, 'asset-guardian/cleanup');
        } catch (\Throwable $e) {
            Craft::error("Asset Guardian: Restore execution error: " . $e->getMessage(), __METHOD__);

            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }

            $this->setFailFlash($e->getMessage());
            return $this->redirectToPostedUrl(null, 'asset-guardian/cleanup');
        }
    }
}
