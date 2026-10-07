<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Json;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use abdulkadiragoliya\assetguardian\records\CleanupOperationRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Duplicates Controller
 *
 * Provides duplicate group analysis, explainable primary recommendations,
 * reference tracing, and safe repointing workflows.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\controllers
 * @since 1.0.0
 */
class DuplicatesController extends BaseCpController
{
    /**
     * @inheritdoc
     */
    protected ?string $requiredPermission = 'assetGuardian-viewDuplicates';

    /**
     * Display Duplicate Groups list with filtering
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;
        $latestScan = $scanService->getLatestCompletedScan();

        $request = Craft::$app->getRequest();
        $volumeFilter = $request->getQueryParam('volume');
        $searchFilter = trim((string)$request->getQueryParam('search', ''));

        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $groups = [];
        $totalDuplicateFiles = 0;
        $totalPotentialBytes = 0;

        if ($latestScan !== null) {
            // Fetch duplicate findings from latest scan
            $findings = (new Query())
                ->select([
                    'f.id as findingId',
                    'f.assetId',
                    'f.size',
                    'f.metadata',
                    'a.filename',
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
                ->where(['f.scanId' => $latestScan->id, 'f.type' => 'duplicate'])
                ->all();

            $groupedByHash = [];

            foreach ($findings as $row) {
                $meta = $row['metadata'] ? Json::decodeIfJson($row['metadata']) : [];
                $hash = $meta['hash'] ?? (string)$row['size'];
                $primaryId = (int)($meta['primaryId'] ?? 0);
                $groupId = (int)($meta['groupId'] ?? 1);

                if (!isset($groupedByHash[$hash])) {
                    $groupedByHash[$hash] = [
                        'groupId' => $groupId,
                        'hash' => $hash,
                        'size' => (int)$row['size'],
                        'primaryId' => $primaryId,
                        'assets' => [],
                    ];
                }

                $groupedByHash[$hash]['assets'][] = [
                    'assetId' => (int)$row['assetId'],
                    'filename' => $row['filename'],
                    'size' => (int)$row['size'],
                    'kind' => $row['kind'],
                    'volumeName' => $row['volumeName'] ?? '—',
                    'volumeHandle' => $row['volumeHandle'] ?? '',
                    'dateModified' => $row['dateModified'],
                    'dateCreated' => $row['dateCreated'],
                    'isPrimary' => ((int)$row['assetId'] === $primaryId),
                ];
            }

            // Also load the primary asset records if they weren't in the findings table
            foreach ($groupedByHash as $hash => &$group) {
                $primaryId = $group['primaryId'];
                $hasPrimaryInAssets = false;
                foreach ($group['assets'] as $a) {
                    if ($a['assetId'] === $primaryId) {
                        $hasPrimaryInAssets = true;
                        break;
                    }
                }

                if (!$hasPrimaryInAssets && $primaryId > 0) {
                    $primaryAsset = Asset::find()->id($primaryId)->one();
                    if ($primaryAsset) {
                        array_unshift($group['assets'], [
                            'assetId' => (int)$primaryAsset->id,
                            'filename' => $primaryAsset->filename,
                            'size' => (int)$primaryAsset->size,
                            'kind' => $primaryAsset->kind,
                            'volumeName' => $primaryAsset->getVolume()?->name ?? '—',
                            'volumeHandle' => $primaryAsset->getVolume()?->handle ?? '',
                            'dateModified' => $primaryAsset->dateModified?->format('Y-m-d H:i:s'),
                            'dateCreated' => $primaryAsset->dateCreated?->format('Y-m-d H:i:s'),
                            'isPrimary' => true,
                        ]);
                    }
                }

                // Batch resolve usages for all assets in this group
                $groupAssetIds = array_map(fn($a) => $a['assetId'], $group['assets']);
                $usageData = $usageService->getUsageForAssetIds($groupAssetIds);

                $mostReferencedId = $primaryId;
                $maxUsage = -1;

                foreach ($group['assets'] as &$assetItem) {
                    $usageInfo = $usageData[$assetItem['assetId']] ?? ['count' => 0];
                    $assetItem['usageCount'] = $usageInfo['count'];
                    $assetItem['formattedSize'] = $scanService->formatBytes($assetItem['size']);

                    if ($usageInfo['count'] > $maxUsage) {
                        $maxUsage = $usageInfo['count'];
                        $mostReferencedId = $assetItem['assetId'];
                    }
                }
                unset($assetItem);

                // Explainable primary recommendation
                if ($maxUsage > 0) {
                    $group['primaryId'] = $mostReferencedId;
                    $group['recommendationReason'] = Craft::t('asset-guardian', 'Most referenced file ({count} references)', ['count' => $maxUsage]);
                } else {
                    $group['recommendationReason'] = Craft::t('asset-guardian', 'Oldest file in duplicate group');
                }

                // Re-mark isPrimary
                foreach ($group['assets'] as &$assetItem) {
                    $assetItem['isPrimary'] = ($assetItem['assetId'] === $group['primaryId']);
                }
                unset($assetItem);

                // Group storage potential = total size of all clone files (excluding primary)
                $cloneBytes = 0;
                foreach ($group['assets'] as $a) {
                    if (!$a['isPrimary']) {
                        $cloneBytes += $a['size'];
                        $totalDuplicateFiles++;
                    }
                }
                $group['potentialBytes'] = $cloneBytes;
                $group['formattedPotential'] = $scanService->formatBytes($cloneBytes);
                $totalPotentialBytes += $cloneBytes;

                // Apply volume & search filters
                if (!empty($volumeFilter)) {
                    $match = false;
                    foreach ($group['assets'] as $a) {
                        if ($a['volumeHandle'] === $volumeFilter) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }

                if (!empty($searchFilter)) {
                    $match = false;
                    foreach ($group['assets'] as $a) {
                        if (stripos($a['filename'], $searchFilter) !== false) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }

                $groups[] = $group;
            }
            unset($group);
        }

        return $this->renderCpTemplate('asset-guardian/duplicates/index', [
            'selectedSubnavItem' => 'duplicates',
            'title' => Craft::t('asset-guardian', 'Duplicates'),
            'latestScan' => $latestScan,
            'groups' => $groups,
            'totalGroups' => count($groups),
            'totalDuplicateFiles' => $totalDuplicateFiles,
            'formattedPotentialBytes' => $scanService->formatBytes($totalPotentialBytes),
            'volumes' => $volumes,
            'currentFilters' => [
                'volume' => $volumeFilter,
                'search' => $searchFilter,
            ],
        ]);
    }

    /**
     * Preview duplicate repointing before execution
     *
     * @return Response
     */
    public function actionRepointReview(): Response
    {
        $this->requireAcceptsJson();

        $sourceId = (int)Craft::$app->getRequest()->getRequiredQueryParam('sourceId');
        $targetId = (int)Craft::$app->getRequest()->getRequiredQueryParam('targetId');

        $source = Craft::$app->getAssets()->getAssetById($sourceId);
        $target = Craft::$app->getAssets()->getAssetById($targetId);

        if (!$source || !$target) {
            throw new NotFoundHttpException('Source or target asset not found.');
        }

        $relations = (new Query())
            ->select(['r.id', 'r.sourceId', 'r.fieldId', 'e.type as elementType'])
            ->from(['r' => '{{%relations}}'])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $sourceId])
            ->all();

        $affectedSources = [];
        foreach ($relations as $rel) {
            $element = Craft::$app->getElements()->getElementById((int)$rel['sourceId']);
            $affectedSources[] = [
                'id' => (int)$rel['sourceId'],
                'title' => $element ? (string)$element : 'Element #' . $rel['sourceId'],
                'type' => $rel['elementType'],
                'fieldId' => $rel['fieldId'] ? (int)$rel['fieldId'] : null,
            ];
        }

        return $this->asJson([
            'success' => true,
            'source' => [
                'id' => $source->id,
                'filename' => $source->filename,
                'size' => AssetGuardian::getInstance()->scan->formatBytes((int)$source->size),
            ],
            'target' => [
                'id' => $target->id,
                'filename' => $target->filename,
                'size' => AssetGuardian::getInstance()->scan->formatBytes((int)$target->size),
            ],
            'referenceCount' => count($relations),
            'affectedSources' => $affectedSources,
        ]);
    }

    /**
     * Safely repoint relations from source asset to target primary asset, then move source to trash
     *
     * @return Response
     */
    public function actionRepoint(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('assetGuardian-manageCleanup');

        $sourceId = (int)Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        $targetId = (int)Craft::$app->getRequest()->getRequiredBodyParam('targetId');

        if ($sourceId === $targetId) {
            throw new BadRequestHttpException('Source and target assets cannot be identical.');
        }

        $source = Craft::$app->getAssets()->getAssetById($sourceId);
        $target = Craft::$app->getAssets()->getAssetById($targetId);

        if (!$source || !$target) {
            throw new NotFoundHttpException('Source or target asset not found.');
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Count relations before repointing
            $relationsCount = (int)(new Query())
                ->from('{{%relations}}')
                ->where(['targetId' => $sourceId])
                ->count();

            // Repoint relations
            if ($relationsCount > 0) {
                $db->createCommand()
                    ->update('{{%relations}}', ['targetId' => $targetId], ['targetId' => $sourceId])
                    ->execute();
            }

            // Move source duplicate asset to Craft Trash
            Craft::$app->getElements()->deleteElement($source);

            // Record cleanup operation
            $operation = new CleanupOperationRecord();
            $operation->userId = Craft::$app->getUser()->getId();
            $operation->status = 'completed';
            $operation->assetCount = 1;
            $operation->totalBytes = (int)$source->size;
            $operation->operation = 'repoint_and_trash';
            $operation->metadata = Json::encode([
                'sourceAssetId' => $sourceId,
                'sourceFilename' => $source->filename,
                'targetAssetId' => $targetId,
                'targetFilename' => $target->filename,
                'repointedReferences' => $relationsCount,
            ]);
            $operation->save(false);

            $transaction->commit();

            Craft::info("Asset Guardian: Repointed {$relationsCount} references from #{$sourceId} to #{$targetId} and moved source to trash.", __METHOD__);

            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'message' => Craft::t('asset-guardian', 'Duplicate successfully repointed and moved to trash.'),
                ]);
            }

            $this->setSuccessFlash(Craft::t('asset-guardian', 'Duplicate successfully repointed and moved to trash.'));
            return $this->redirect('asset-guardian/duplicates');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Asset Guardian: Repointing failed: " . $e->getMessage(), __METHOD__);

            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }

            $this->setFailFlash($e->getMessage());
            return $this->redirect('asset-guardian/duplicates');
        }
    }
}
