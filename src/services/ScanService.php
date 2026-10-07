<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use abdulkadiragoliya\assetguardian\db\Table;
use abdulkadiragoliya\assetguardian\jobs\ScanAssetsJob;
use abdulkadiragoliya\assetguardian\records\FindingRecord;
use abdulkadiragoliya\assetguardian\records\ScanRecord;
use yii\queue\Queue;

/**
 * Scan Service for Asset Guardian
 *
 * Coordinates asynchronous scans, chunked processing, database snapshots,
 * and library health evaluations.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\services
 * @since 1.0.0
 */
class ScanService extends Component
{
    /**
     * Initiates a new library scan and queues the background job
     *
     * @param int|null $userId
     * @return ScanRecord
     */
    public function startScan(?int $userId = null): ScanRecord
    {
        // Cancel or mark stale any existing running scan
        $runningScan = $this->getRunningScan();
        if ($runningScan !== null) {
            $runningScan->status = 'failed';
            $runningScan->errorMessage = Craft::t('asset-guardian', 'Superseded by a new scan request.');
            $runningScan->completedAt = Db::prepareDateForDb(new \DateTime());
            $runningScan->save();
        }

        $scan = new ScanRecord();
        $scan->status = 'pending';
        $scan->startedAt = Db::prepareDateForDb(new \DateTime());
        $scan->totalAssets = $this->getTotalAssetCount();
        $scan->healthScore = 100;
        $scan->save(false);

        // Queue background job
        Craft::$app->getQueue()->push(new ScanAssetsJob([
            'scanId' => (int)$scan->id,
            'description' => Craft::t('asset-guardian', 'Asset Guardian: Scanning asset library'),
        ]));

        return $scan;
    }

    /**
     * Executes the comprehensive asset library scan
     *
     * @param int $scanId
     * @param ScanAssetsJob|null $job
     * @param Queue|null $queue
     * @return void
     * @throws \Throwable
     */
    public function executeScan(int $scanId, ?ScanAssetsJob $job = null, ?Queue $queue = null): void
    {
        $scan = ScanRecord::findOne($scanId);
        if (!$scan) {
            Craft::error("Asset Guardian: Scan #{$scanId} not found.", __METHOD__);
            return;
        }

        $scan->status = 'running';
        $scan->startedAt = Db::prepareDateForDb(new \DateTime());
        $scan->save(false);

        try {
            $settings = AssetGuardian::getInstance()->getSettings();
            $healthService = AssetGuardian::getInstance()->health;
            $usageService = AssetGuardian::getInstance()->usage;
            $duplicateService = AssetGuardian::getInstance()->duplicate;

            // Resolve target volume IDs if restricted in settings
            $targetVolumeIds = null;
            if (!empty($settings->enabledVolumes)) {
                $targetVolumeIds = (new Query())
                    ->select(['id'])
                    ->from('{{%volumes}}')
                    ->where(['handle' => $settings->enabledVolumes])
                    ->column();
            }

            // Query base asset IDs to scan
            $baseAssetQuery = (new Query())
                ->select(['a.id', 'a.size', 'a.kind', 'a.alt', 'e.dateCreated', 'a.volumeId'])
                ->from(['a' => '{{%assets}}'])
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[a.id]]')
                ->where(['e.dateDeleted' => null]);

            if (!empty($targetVolumeIds)) {
                $baseAssetQuery->andWhere(['a.volumeId' => $targetVolumeIds]);
            }

            $allAssets = $baseAssetQuery->all();
            $totalAssets = count($allAssets);

            if ($job && $queue) {
                $job->updateProgress($queue, 0.10, Craft::t('asset-guardian', 'Scanning assets & file sizes...'));
            }

            $largeCount = 0;
            $missingAltCount = 0;
            $imageCount = 0;
            $largeThreshold = $settings->largeFileThresholdBytes;
            $scanMissingAlt = $settings->scanMissingAlt;

            $assetMap = [];
            $allAssetIds = [];

            // 1. Analyze size thresholds and alt text
            foreach ($allAssets as $row) {
                $assetId = (int)$row['id'];
                $size = (int)$row['size'];
                $kind = (string)($row['kind'] ?? '');
                $alt = trim((string)($row['alt'] ?? ''));

                $allAssetIds[] = $assetId;
                $assetMap[$assetId] = $row;

                if ($kind === 'image') {
                    $imageCount++;
                    if ($scanMissingAlt && empty($alt)) {
                        $missingAltCount++;
                        $this->_createFinding($scan->id, $assetId, 'missing-alt', 'low', $size, 'Image is missing alt text metadata.');
                    }
                }

                if ($size > $largeThreshold) {
                    $largeCount++;
                    $formattedSize = $this->formatBytes($size);
                    $this->_createFinding($scan->id, $assetId, 'large', 'medium', $size, "File exceeds configured threshold ({$formattedSize}).");
                }
            }

            if ($job && $queue) {
                $job->updateProgress($queue, 0.45, Craft::t('asset-guardian', 'Checking duplicate files...'));
            }

            // 2. Detect exact duplicates
            $duplicateGroups = $duplicateService->findDuplicates($targetVolumeIds);
            $duplicateCount = 0;

            foreach ($duplicateGroups as $group) {
                $primaryId = $group['primaryId'];
                foreach ($group['assetIds'] as $dAssetId) {
                    if ($dAssetId !== $primaryId) {
                        $duplicateCount++;
                        $dupSize = $assetMap[$dAssetId]['size'] ?? $group['size'];
                        $this->_createFinding(
                            $scan->id,
                            $dAssetId,
                            'duplicate',
                            'medium',
                            (int)$dupSize,
                            "Exact duplicate of primary asset #{$primaryId}.",
                            [
                                'groupId' => $group['groupId'],
                                'primaryId' => $primaryId,
                                'hash' => $group['hash'],
                                'duplicateCount' => count($group['assetIds']),
                            ]
                        );
                    }
                }
            }

            if ($job && $queue) {
                $job->updateProgress($queue, 0.65, Craft::t('asset-guardian', 'Analyzing relationships & usage...'));
            }

            // 3. Batch relationship and usage analysis (chunked in batches of 100)
            $unusedCount = 0;
            $chunks = array_chunk($allAssetIds, 100);
            $chunkIndex = 0;
            $totalChunks = max(1, count($chunks));

            foreach ($chunks as $chunkIds) {
                $usageData = $usageService->getUsageForAssetIds($chunkIds);

                foreach ($chunkIds as $cAssetId) {
                    $usageInfo = $usageData[$cAssetId] ?? ['count' => 0, 'sources' => []];
                    $usageCount = $usageInfo['count'];

                    if ($usageCount === 0) {
                        $unusedCount++;
                        $assetRow = $assetMap[$cAssetId] ?? [];
                        $risk = $usageService->determineRisk(0, $assetRow['dateCreated'] ?? null);
                        $size = (int)($assetRow['size'] ?? 0);

                        $this->_createFinding(
                            $scan->id,
                            $cAssetId,
                            'unused',
                            $risk,
                            $size,
                            'No supported Craft relations detected across content, matrix, or fields.',
                            [
                                'usageCount' => 0,
                                'dateCreated' => $assetRow['dateCreated'] ?? null,
                            ]
                        );
                    }
                }

                $chunkIndex++;
                if ($job && $queue) {
                    $progress = 0.65 + (0.25 * ($chunkIndex / $totalChunks));
                    $job->updateProgress($queue, $progress, Craft::t('asset-guardian', 'Analyzing relationships & usage...'));
                }
            }

            if ($job && $queue) {
                $job->updateProgress($queue, 0.92, Craft::t('asset-guardian', 'Calculating health score & storage...'));
            }

            // 4. Calculate unique potential recovery (avoiding double-counting duplicate clones and unused files)
            $recoverableAssetIds = (new Query())
                ->select(['assetId'])
                ->distinct()
                ->from(Table::FINDINGS)
                ->where(['scanId' => $scan->id])
                ->andWhere(['type' => ['unused', 'duplicate']])
                ->column();

            $potentialRecovery = 0;
            if (!empty($recoverableAssetIds)) {
                $recoverySum = (new Query())
                    ->from('{{%assets}}')
                    ->where(['id' => $recoverableAssetIds])
                    ->sum('size');
                $potentialRecovery = (int)($recoverySum ?: 0);
            }

            // 5. Calculate deterministic health score
            $healthScore = $healthService->calculateScore(
                $totalAssets,
                $unusedCount,
                $duplicateCount,
                $largeCount,
                $missingAltCount,
                $imageCount
            );

            // 6. Complete and save scan record
            $scan->status = 'completed';
            $scan->completedAt = Db::prepareDateForDb(new \DateTime());
            $scan->totalAssets = $totalAssets;
            $scan->unusedCount = $unusedCount;
            $scan->duplicateCount = $duplicateCount;
            $scan->largeFileCount = $largeCount;
            $scan->missingAltCount = $missingAltCount;
            $scan->potentialRecovery = $potentialRecovery;
            $scan->healthScore = $healthScore;
            $scan->errorMessage = null;
            $scan->save(false);

            if ($job && $queue) {
                $job->updateProgress($queue, 1.0, Craft::t('asset-guardian', 'Scan completed.'));
            }

            Craft::info("Asset Guardian: Scan #{$scan->id} completed with health score {$healthScore}/100.", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Asset Guardian: Scan #{$scan->id} failed: " . $e->getMessage(), __METHOD__);
            $scan->status = 'failed';
            $scan->errorMessage = $e->getMessage();
            $scan->completedAt = Db::prepareDateForDb(new \DateTime());
            $scan->save(false);

            throw $e;
        }
    }

    /**
     * Helper to insert a finding record
     *
     * @param int $scanId
     * @param int $assetId
     * @param string $type
     * @param string $risk
     * @param int $size
     * @param string|null $reason
     * @param array|null $metadata
     */
    private function _createFinding(
        int $scanId,
        int $assetId,
        string $type,
        string $risk,
        int $size,
        ?string $reason = null,
        ?array $metadata = null
    ): void {
        $finding = new FindingRecord();
        $finding->scanId = $scanId;
        $finding->assetId = $assetId;
        $finding->type = $type;
        $finding->risk = $risk;
        $finding->size = $size;
        $finding->reason = $reason;
        $finding->metadata = $metadata ? Json::encode($metadata) : null;
        $finding->save(false);
    }

    /**
     * Returns the latest completed scan record
     *
     * @return ScanRecord|null
     */
    public function getLatestCompletedScan(): ?ScanRecord
    {
        return ScanRecord::find()
            ->where(['status' => 'completed'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /**
     * Returns any currently running or pending scan
     *
     * @return ScanRecord|null
     */
    public function getRunningScan(): ?ScanRecord
    {
        return ScanRecord::find()
            ->where(['status' => ['pending', 'running']])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /**
     * Returns the latest scan regardless of status
     *
     * @return ScanRecord|null
     */
    public function getLatestScan(): ?ScanRecord
    {
        return ScanRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /**
     * Returns total count of Craft asset elements
     *
     * @return int
     */
    public function getTotalAssetCount(): int
    {
        return (int)Asset::find()->count();
    }

    /**
     * Returns total bytes of all Craft assets
     *
     * @return int
     */
    public function getTotalAssetBytes(): int
    {
        $sum = (new Query())
            ->from('{{%assets}}')
            ->sum('size');

        return (int)($sum ?: 0);
    }

    /**
     * Returns storage breakdown grouped by Asset Volume
     *
     * @return array
     */
    public function getStorageByVolume(): array
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        if (empty($volumes)) {
            return [];
        }

        $volumeStats = (new Query())
            ->select([
                'volumeId',
                'COUNT(*) as assetCount',
                'SUM([[size]]) as totalBytes',
            ])
            ->from('{{%assets}}')
            ->groupBy(['volumeId'])
            ->indexBy('volumeId')
            ->all();

        $totalLibraryBytes = $this->getTotalAssetBytes();
        $results = [];

        foreach ($volumes as $volume) {
            $stats = $volumeStats[$volume->id] ?? null;
            $bytes = (int)($stats['totalBytes'] ?? 0);
            $count = (int)($stats['assetCount'] ?? 0);
            $percent = $totalLibraryBytes > 0 ? round(($bytes / $totalLibraryBytes) * 100, 1) : 0;

            $results[] = [
                'id' => $volume->id,
                'name' => $volume->name,
                'handle' => $volume->handle,
                'assetCount' => $count,
                'totalBytes' => $bytes,
                'formattedBytes' => $this->formatBytes($bytes),
                'percentage' => $percent,
            ];
        }

        usort($results, fn($a, $b) => $b['totalBytes'] <=> $a['totalBytes']);

        return $results;
    }

    /**
     * Returns storage breakdown grouped by file kind
     *
     * @return array
     */
    public function getStorageByKind(): array
    {
        $rows = (new Query())
            ->select([
                'kind',
                'COUNT(*) as assetCount',
                'SUM([[size]]) as totalBytes',
            ])
            ->from('{{%assets}}')
            ->groupBy(['kind'])
            ->all();

        $totalLibraryBytes = $this->getTotalAssetBytes();
        $results = [];

        foreach ($rows as $row) {
            $kind = $row['kind'] ?: 'other';
            $bytes = (int)($row['totalBytes'] ?? 0);
            $count = (int)($row['assetCount'] ?? 0);
            $percent = $totalLibraryBytes > 0 ? round(($bytes / $totalLibraryBytes) * 100, 1) : 0;

            $results[] = [
                'kind' => ucfirst($kind),
                'assetCount' => $count,
                'totalBytes' => $bytes,
                'formattedBytes' => $this->formatBytes($bytes),
                'percentage' => $percent,
            ];
        }

        usort($results, fn($a, $b) => $b['totalBytes'] <=> $a['totalBytes']);

        return $results;
    }

    /**
     * Calculates health score evaluation properties
     *
     * @param ScanRecord|null $scan
     * @return array
     */
    public function getHealthScoreEvaluation(?ScanRecord $scan): array
    {
        if ($scan === null) {
            return [
                'score' => 100,
                'label' => Craft::t('asset-guardian', 'Not Scanned'),
                'color' => '#6b7280',
                'badgeClass' => 'light',
                'description' => Craft::t('asset-guardian', 'Run your first scan to evaluate library health.'),
            ];
        }

        $score = $scan->healthScore;

        if ($score >= 90) {
            return [
                'score' => $score,
                'label' => Craft::t('asset-guardian', 'Excellent'),
                'color' => '#10b981',
                'badgeClass' => 'success',
                'description' => Craft::t('asset-guardian', 'Your asset library is exceptionally healthy with minimal clutter.'),
            ];
        }

        if ($score >= 75) {
            return [
                'score' => $score,
                'label' => Craft::t('asset-guardian', 'Good'),
                'color' => '#0284c7',
                'badgeClass' => 'info',
                'description' => Craft::t('asset-guardian', 'Healthy overall with a few cleanup or optimization opportunities.'),
            ];
        }

        if ($score >= 50) {
            return [
                'score' => $score,
                'label' => Craft::t('asset-guardian', 'Needs Attention'),
                'color' => '#f59e0b',
                'badgeClass' => 'warning',
                'description' => Craft::t('asset-guardian', 'A notable number of unreferenced files, duplicates, or oversized assets detected.'),
            ];
        }

        return [
            'score' => $score,
            'label' => Craft::t('asset-guardian', 'Poor'),
            'color' => '#ef4444',
            'badgeClass' => 'error',
            'description' => Craft::t('asset-guardian', 'Significant storage waste or accessibility deficiencies require remediation.'),
        ];
    }

    /**
     * Format bytes into human-readable string
     *
     * @param int|float $bytes
     * @param int $precision
     * @return string
     */
    public function formatBytes(int|float $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);

        return round($value, $precision) . ' ' . $units[$power];
    }

    /**
     * Format duration between two timestamps
     *
     * @param string|null $startedAt
     * @param string|null $completedAt
     * @return string
     */
    public function formatDuration(?string $startedAt, ?string $completedAt): string
    {
        if (!$startedAt || !$completedAt) {
            return '—';
        }

        $start = DateTimeHelper::toDateTime($startedAt);
        $end = DateTimeHelper::toDateTime($completedAt);

        if (!$start || !$end) {
            return '—';
        }

        $diffSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());

        if ($diffSeconds < 60) {
            return $diffSeconds . 's';
        }

        $minutes = floor($diffSeconds / 60);
        $seconds = $diffSeconds % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }
}
