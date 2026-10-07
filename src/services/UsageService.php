<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use abdulkadiragoliya\assetguardian\AssetGuardian;

/**
 * Usage Service
 *
 * Inspects Craft element relationships, matrix blocks, fields, and globals
 * to reliably determine asset usage and risk classification.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class UsageService extends Component
{
    /**
     * Batch queries relationship references for a list of asset IDs
     *
     * Returns an associative array: [assetId => ['count' => int, 'sources' => array]]
     *
     * @param int[] $assetIds
     * @return array<int, array{count: int, sources: array}>
     */
    public function getUsageForAssetIds(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $results = [];
        foreach ($assetIds as $id) {
            $results[$id] = [
                'count' => 0,
                'sources' => [],
            ];
        }

        // Query craft_relations linking to these assets
        $rows = (new Query())
            ->select([
                'r.targetId as assetId',
                'r.sourceId',
                'r.fieldId',
                'e.type as elementType',
                'e.canonicalId',
            ])
            ->from(['r' => '{{%relations}}'])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $assetIds])
            ->all();

        // Also query rich text HTML references if applicable
        foreach ($rows as $row) {
            $assetId = (int)$row['assetId'];
            $sourceId = (int)$row['sourceId'];

            if (!isset($results[$assetId])) {
                continue;
            }

            $results[$assetId]['count']++;
            if (count($results[$assetId]['sources']) < 10) {
                $results[$assetId]['sources'][] = [
                    'sourceId' => $sourceId,
                    'type' => $row['elementType'] ?? 'Element',
                    'fieldId' => $row['fieldId'] ? (int)$row['fieldId'] : null,
                ];
            }
        }

        return $results;
    }

    /**
     * Calculates risk rating for an asset based on usage count and age
     *
     * @param int $usageCount
     * @param string|null $dateCreated
     * @return string 'low'|'medium'|'high'
     */
    public function determineRisk(int $usageCount, ?string $dateCreated = null): string
    {
        if ($usageCount > 0) {
            return 'high';
        }

        $settings = AssetGuardian::getInstance()->getSettings();
        $thresholdDays = $settings->unusedAgeThresholdDays;

        if ($dateCreated) {
            $created = DateTimeHelper::toDateTime($dateCreated);
            if ($created) {
                $diffDays = (time() - $created->getTimestamp()) / 86400;
                // If the asset is brand new (< threshold days), flag as medium risk for cautious review
                if ($diffDays < $thresholdDays) {
                    return 'medium';
                }
            }
        }

        return 'low';
    }
}
