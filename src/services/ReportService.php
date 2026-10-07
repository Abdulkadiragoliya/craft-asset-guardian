<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;

/**
 * Report Service
 *
 * Generates audit reports and CSV exports for asset health,
 * unused assets, duplicate groups, and accessibility compliance.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class ReportService extends Component
{
    /**
     * Generates CSV string for Unused Assets report
     *
     * @param int|null $scanId
     * @return string
     */
    public function exportUnusedAssetsCsv(?int $scanId = null): string
    {
        $scanService = AssetGuardian::getInstance()->scan;
        if ($scanId === null) {
            $latest = $scanService->getLatestCompletedScan();
            $scanId = $latest ? (int)$latest->id : 0;
        }

        $query = (new Query())
            ->select([
                'f.assetId',
                'a.filename',
                'v.name as volumeName',
                'a.size',
                'a.kind',
                'f.risk',
                'f.reason',
                'e.dateCreated',
            ])
            ->from(['f' => Table::FINDINGS])
            ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[f.assetId]]')
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where(['f.scanId' => $scanId, 'f.type' => 'unused'])
            ->andWhere(['e.dateDeleted' => null])
            ->orderBy(['f.size' => SORT_DESC]);

        $rows = $query->all();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Asset ID', 'Filename', 'Volume', 'Size (Bytes)', 'Size (Formatted)', 'Kind', 'Risk Level', 'Date Created', 'Reason']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['assetId'],
                $row['filename'],
                $row['volumeName'] ?? '—',
                $row['size'],
                $scanService->formatBytes((int)$row['size']),
                $row['kind'],
                strtoupper($row['risk']),
                $row['dateCreated'],
                $row['reason'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string)$csv;
    }

    /**
     * Generates CSV string for Duplicate Assets report
     *
     * @param int|null $scanId
     * @return string
     */
    public function exportDuplicatesCsv(?int $scanId = null): string
    {
        $scanService = AssetGuardian::getInstance()->scan;
        if ($scanId === null) {
            $latest = $scanService->getLatestCompletedScan();
            $scanId = $latest ? (int)$latest->id : 0;
        }

        $query = (new Query())
            ->select([
                'f.assetId',
                'f.metadata',
                'f.size',
                'a.filename',
                'v.name as volumeName',
                'e.dateCreated',
            ])
            ->from(['f' => Table::FINDINGS])
            ->innerJoin(['a' => '{{%assets}}'], '[[a.id]] = [[f.assetId]]')
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where(['f.scanId' => $scanId, 'f.type' => 'duplicate'])
            ->andWhere(['e.dateDeleted' => null])
            ->orderBy(['f.size' => SORT_DESC]);

        $rows = $query->all();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Duplicate Asset ID', 'Duplicate Filename', 'Primary Asset ID', 'Volume', 'Size (Bytes)', 'Size (Formatted)', 'Content Hash', 'Date Created']);

        foreach ($rows as $row) {
            $meta = json_decode($row['metadata'] ?? '{}', true);
            fputcsv($output, [
                $row['assetId'],
                $row['filename'],
                $meta['primaryId'] ?? '—',
                $row['volumeName'] ?? '—',
                $row['size'],
                $scanService->formatBytes((int)$row['size']),
                $meta['hash'] ?? '—',
                $row['dateCreated'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string)$csv;
    }

    /**
     * Generates CSV string for Missing Alt Text report
     *
     * @return string
     */
    public function exportMissingAltCsv(): string
    {
        $scanService = AssetGuardian::getInstance()->scan;
        $usageService = AssetGuardian::getInstance()->usage;

        $rows = (new Query())
            ->select([
                'a.id as assetId',
                'a.filename',
                'a.size',
                'a.width',
                'a.height',
                'v.name as volumeName',
                'e.dateCreated',
            ])
            ->from(['a' => '{{%assets}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
            ->leftJoin(['v' => '{{%volumes}}'], '[[v.id]] = [[a.volumeId]]')
            ->where(['a.kind' => 'image'])
            ->andWhere(['or', ['a.alt' => null], ['a.alt' => '']])
            ->andWhere(['e.dateDeleted' => null])
            ->orderBy(['e.dateCreated' => SORT_DESC])
            ->all();

        $assetIds = array_map(fn($r) => (int)$r['assetId'], $rows);
        $usageData = $usageService->getUsageForAssetIds($assetIds);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Asset ID', 'Filename', 'Volume', 'Size (Bytes)', 'Dimensions', 'Relations Count', 'Date Created']);

        foreach ($rows as $row) {
            $assetId = (int)$row['assetId'];
            $usage = $usageData[$assetId] ?? ['count' => 0];
            $dimensions = ($row['width'] && $row['height']) ? "{$row['width']}x{$row['height']}" : '—';

            fputcsv($output, [
                $assetId,
                $row['filename'],
                $row['volumeName'] ?? '—',
                $row['size'],
                $dimensions,
                $usage['count'],
                $row['dateCreated'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string)$csv;
    }

    /**
     * Generates comprehensive Library Health Audit Summary CSV
     *
     * @param int|null $scanId
     * @return string
     */
    public function exportHealthAuditCsv(?int $scanId = null): string
    {
        $scanService = AssetGuardian::getInstance()->scan;
        if ($scanId === null) {
            $latest = $scanService->getLatestCompletedScan();
            $scanId = $latest ? (int)$latest->id : 0;
        }

        $scan = $scanId > 0 ? \abdulkadiragoliya\assetguardian\records\ScanRecord::findOne($scanId) : null;

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Metric', 'Value', 'Notes']);
        fputcsv($output, ['Scan ID', $scan ? "#{$scan->id}" : 'N/A', 'Scan Reference']);
        $healthEval = $scan ? $scanService->getHealthScoreEvaluation($scan) : ['score' => 100, 'label' => 'Optimal'];
        fputcsv($output, ['Health Score', ($scan ? $scan->healthScore : 100) . '/100', $healthEval['label']]);
        fputcsv($output, ['Total Assets', $scan ? $scan->totalAssets : $scanService->getTotalAssetCount(), 'Total asset count in library']);
        fputcsv($output, ['Total Storage', $scanService->formatBytes($scanService->getTotalAssetBytes()), 'Total disk storage footprint']);
        fputcsv($output, ['Unused Assets', $scan ? $scan->unusedCount : 0, 'Zero content relations detected']);
        fputcsv($output, ['Duplicate Clones', $scan ? $scan->duplicateCount : 0, 'Exact content duplicate files']);
        fputcsv($output, ['Large Files', $scan ? $scan->largeFileCount : 0, 'Exceeding configured threshold']);
        fputcsv($output, ['Missing Alt Text', $scan ? $scan->missingAltCount : 0, 'Images lacking accessibility alt text']);
        fputcsv($output, ['Potential Reclaimable Storage', $scan ? $scanService->formatBytes((int)$scan->potentialRecovery) : '0 B', 'Safe recovery opportunity']);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string)$csv;
    }
}
