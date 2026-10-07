<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\records;

use craft\db\ActiveRecord;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Scan Record
 *
 * @property int $id
 * @property string $status
 * @property string|null $startedAt
 * @property string|null $completedAt
 * @property int $totalAssets
 * @property int $unusedCount
 * @property int $duplicateCount
 * @property int $largeFileCount
 * @property int $missingAltCount
 * @property int $potentialRecovery
 * @property int $healthScore
 * @property string|null $errorMessage
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @property-read FindingRecord[] $findings
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\records
 * @since 1.0.0
 */
class ScanRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::SCANS;
    }

    /**
     * Returns findings associated with this scan
     *
     * @return ActiveQueryInterface
     */
    public function getFindings(): ActiveQueryInterface
    {
        return $this->hasMany(FindingRecord::class, ['scanId' => 'id']);
    }
}
