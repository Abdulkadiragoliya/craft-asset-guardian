<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\jobs;

use Craft;
use craft\queue\BaseJob;
use abdulkadiragoliya\assetguardian\AssetGuardian;
use yii\queue\Queue;

/**
 * Scan Assets Queue Job
 *
 * Background coordinator processing library scanning, usage checks,
 * duplicate detection, and health score calculations in batches.
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\jobs
 * @since 1.0.0
 */
class ScanAssetsJob extends BaseJob
{
    /**
     * @var int ID of the ScanRecord being processed
     */
    public int $scanId;

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('asset-guardian', 'Asset Guardian: Scanning asset library');
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        AssetGuardian::getInstance()->scan->executeScan($this->scanId, $this, $queue);
    }

    /**
     * Expose setProgress for the scan service to update queue progress
     *
     * @param Queue $queue
     * @param float $progress
     * @param string|null $label
     */
    public function updateProgress(Queue $queue, float $progress, ?string $label = null): void
    {
        $this->setProgress($queue, $progress, $label);
    }
}
