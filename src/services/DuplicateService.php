<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;

/**
 * Duplicate Detection Service
 *
 * Efficiently detects exact duplicate files by filtering identical byte sizes
 * and verifying content hash fingerprints.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class DuplicateService extends Component
{
    /**
     * Finds exact duplicate asset groups across the library or enabled volumes
     *
     * @param int[]|null $volumeIds Filter by specific volume IDs, or null for all
     * @return array<string, array{hash: string, size: int, assetIds: int[], primaryId: int}>
     */
    public function findDuplicates(?array $volumeIds = null): array
    {
        // 1. Find file sizes that appear more than once
        $sizeQuery = (new Query())
            ->select(['size', 'COUNT(*) as count'])
            ->from('{{%assets}}')
            ->where(['>', 'size', 0]);

        if (!empty($volumeIds)) {
            $sizeQuery->andWhere(['volumeId' => $volumeIds]);
        }

        $duplicateSizes = $sizeQuery
            ->groupBy(['size'])
            ->having(['>', 'COUNT(*)', 1])
            ->column();

        if (empty($duplicateSizes)) {
            return [];
        }

        // 2. Fetch asset records sharing these identical sizes
        $assetQuery = Asset::find()
            ->select(['elements.id', 'assets.size', 'assets.filename', 'assets.volumeId', 'elements.dateCreated'])
            ->where(['assets.size' => $duplicateSizes]);

        if (!empty($volumeIds)) {
            $assetQuery->volumeId($volumeIds);
        }

        /** @var Asset[] $assets */
        $assets = $assetQuery->all();

        // 3. Compute file content hashes for candidate assets
        $hashes = [];
        foreach ($assets as $asset) {
            $hash = $this->calculateAssetHash($asset);
            if ($hash !== null) {
                $key = $asset->size . '_' . $hash;
                $hashes[$key][] = $asset;
            }
        }

        // 4. Form duplicate groups where 2+ assets share the exact content hash
        $duplicateGroups = [];
        $groupIndex = 1;

        foreach ($hashes as $key => $groupAssets) {
            if (count($groupAssets) < 2) {
                continue;
            }

            // Pick recommended primary asset (oldest dateCreated or most referenced)
            usort($groupAssets, function(Asset $a, Asset $b) {
                return ($a->dateCreated?->getTimestamp() ?? 0) <=> ($b->dateCreated?->getTimestamp() ?? 0);
            });

            $primaryAsset = $groupAssets[0];
            $assetIds = array_map(fn(Asset $a) => (int)$a->id, $groupAssets);

            $duplicateGroups['group_' . $groupIndex] = [
                'groupId' => $groupIndex,
                'hash' => substr($key, strpos($key, '_') + 1),
                'size' => (int)$primaryAsset->size,
                'assetIds' => $assetIds,
                'primaryId' => (int)$primaryAsset->id,
            ];

            $groupIndex++;
        }

        return $duplicateGroups;
    }

    /**
     * Computes MD5 hash of an asset's file content
     *
     * @param Asset $asset
     * @return string|null
     */
    public function calculateAssetHash(Asset $asset): ?string
    {
        try {
            $stream = $asset->getStream();
            if ($stream && is_resource($stream)) {
                $ctx = hash_init('md5');
                hash_update_stream($ctx, $stream);
                $hash = hash_final($ctx);
                fclose($stream);
                return $hash;
            }

            // Fallback: check local copy if stream is unavailable
            $localCopy = $asset->getCopyOfFile();
            if ($localCopy && file_exists($localCopy)) {
                $hash = md5_file($localCopy);
                @unlink($localCopy);
                return $hash ?: null;
            }
        } catch (\Throwable $e) {
            Craft::warning("Unable to compute content hash for asset ID {$asset->id}: " . $e->getMessage(), __METHOD__);
        }

        return null;
    }
}
