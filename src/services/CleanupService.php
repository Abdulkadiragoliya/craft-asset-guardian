<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use abdulkadiragoliya\assetguardian\records\CleanupOperationRecord;

/**
 * Cleanup Service
 *
 * Implements safe, auditable cleanup workflows.
 * Assets are moved to Craft Trash by default (soft delete), never
 * permanently deleted unless explicitly configured.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class CleanupService extends Component
{
    /**
     * Simulates a cleanup operation without executing deletions
     *
     * @param int[] $assetIds
     * @return array
     */
    public function simulateCleanup(array $assetIds): array
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        if (empty($assetIds)) {
            return [
                'totalSelected' => 0,
                'safeCount' => 0,
                'warningCount' => 0,
                'totalBytes' => 0,
                'formattedBytes' => '0 B',
                'items' => [],
            ];
        }

        $assets = (new Query())
            ->select(['a.id as assetId', 'a.filename', 'a.size', 'a.kind', 'e.dateCreated'])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['a.id' => $assetIds])
            ->andWhere(['e.dateDeleted' => null])
            ->all();

        $usageData = $usageService->getUsageForAssetIds($assetIds);

        $safeCount = 0;
        $warningCount = 0;
        $totalBytes = 0;
        $items = [];

        foreach ($assets as $row) {
            $assetId = (int)$row['assetId'];
            $usageInfo = $usageData[$assetId] ?? ['count' => 0, 'sources' => []];
            $usageCount = $usageInfo['count'];
            $size = (int)$row['size'];
            $totalBytes += $size;

            $risk = $usageService->determineRisk($usageCount, $row['dateCreated']);

            if ($risk === 'high' || $usageCount > 0) {
                $warningCount++;
            } else {
                $safeCount++;
            }

            $items[] = [
                'assetId' => $assetId,
                'filename' => $row['filename'],
                'size' => $size,
                'formattedSize' => $scanService->formatBytes($size),
                'usageCount' => $usageCount,
                'risk' => $risk,
                'canSafelyTrash' => ($usageCount === 0),
            ];
        }

        return [
            'totalSelected' => count($items),
            'safeCount' => $safeCount,
            'warningCount' => $warningCount,
            'totalBytes' => $totalBytes,
            'formattedBytes' => $scanService->formatBytes($totalBytes),
            'items' => $items,
        ];
    }

    /**
     * Executes safe cleanup by moving selected assets to Craft Trash
     *
     * @param int[] $assetIds
     * @param int|null $userId
     * @param bool $hardDelete
     * @return array
     * @throws \Throwable
     */
    public function executeCleanup(array $assetIds, ?int $userId = null, bool $hardDelete = false): array
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $elementsService = Craft::$app->getElements();
        $db = Craft::$app->getDb();

        if (empty($assetIds)) {
            return [
                'success' => false,
                'message' => Craft::t('asset-guardian', 'No assets selected for cleanup.'),
            ];
        }

        // Fetch asset records to calculate reclaimed storage and track audit data
        $assets = (new Query())
            ->select(['a.id as assetId', 'a.filename', 'a.size'])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->where(['a.id' => $assetIds])
            ->andWhere(['e.dateDeleted' => null])
            ->all();

        if (empty($assets)) {
            return [
                'success' => false,
                'message' => Craft::t('asset-guardian', 'No valid active assets found for cleanup.'),
            ];
        }

        $transaction = $db->beginTransaction();

        try {
            $totalBytes = 0;
            $processedIds = [];
            $auditItems = [];

            foreach ($assets as $assetRow) {
                $assetId = (int)$assetRow['assetId'];
                $size = (int)$assetRow['size'];

                /** @var Asset|null $assetElement */
                $assetElement = Asset::find()->id($assetId)->one();
                if ($assetElement) {
                    // Always deleteElement with $hardDelete = false (move to Trash) unless explicitly requested
                    $elementsService->deleteElement($assetElement, $hardDelete);

                    $totalBytes += $size;
                    $processedIds[] = $assetId;
                    $auditItems[] = [
                        'assetId' => $assetId,
                        'filename' => $assetRow['filename'],
                        'size' => $size,
                    ];
                }
            }

            // Remove or update findings for deleted assets
            if (!empty($processedIds)) {
                $db->createCommand()
                    ->delete(Table::FINDINGS, ['assetId' => $processedIds])
                    ->execute();
            }

            // Record audit trail operation
            $operation = new CleanupOperationRecord();
            $operation->userId = $userId;
            $operation->status = 'completed';
            $operation->assetCount = count($processedIds);
            $operation->totalBytes = $totalBytes;
            $operation->operation = $hardDelete ? 'hard_delete' : 'soft_delete';
            $operation->metadata = json_encode([
                'assetIds' => $processedIds,
                'items' => $auditItems,
                'hardDelete' => $hardDelete,
            ]);
            $operation->save(false);

            $transaction->commit();

            return [
                'success' => true,
                'assetCount' => count($processedIds),
                'totalBytes' => $totalBytes,
                'formattedBytes' => $scanService->formatBytes($totalBytes),
                'operationId' => (int)$operation->id,
            ];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Asset Guardian: Cleanup failed: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Restores assets from a previous cleanup operation (from Craft Trash)
     *
     * @param int $operationId
     * @return array
     * @throws \Throwable
     */
    public function restoreOperation(int $operationId): array
    {
        $operation = CleanupOperationRecord::findOne($operationId);
        if (!$operation) {
            return [
                'success' => false,
                'message' => Craft::t('asset-guardian', 'Operation not found.'),
            ];
        }

        if ($operation->operation === 'hard_delete') {
            return [
                'success' => false,
                'message' => Craft::t('asset-guardian', 'Permanently deleted assets cannot be restored.'),
            ];
        }

        $meta = json_decode($operation->metadata ?? '{}', true);
        $assetIds = $meta['assetIds'] ?? [];

        if (empty($assetIds)) {
            return [
                'success' => false,
                'message' => Craft::t('asset-guardian', 'No assets found in operation metadata to restore.'),
            ];
        }

        $elementsService = Craft::$app->getElements();
        $restoredCount = 0;

        foreach ($assetIds as $assetId) {
            /** @var Asset|null $trashedAsset */
            $trashedAsset = Asset::find()->id((int)$assetId)->trashed(true)->one();
            if ($trashedAsset) {
                if ($elementsService->restoreElement($trashedAsset)) {
                    $restoredCount++;
                }
            }
        }

        $operation->status = 'restored';
        $operation->save(false);

        return [
            'success' => true,
            'restoredCount' => $restoredCount,
            'message' => Craft::t('asset-guardian', 'Successfully restored {count} assets.', ['count' => $restoredCount]),
        ];
    }
}
