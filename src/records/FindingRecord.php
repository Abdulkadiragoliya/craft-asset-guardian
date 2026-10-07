<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Finding Record
 *
 * @property int $id
 * @property int $scanId
 * @property int $assetId
 * @property string $type
 * @property string $risk
 * @property int $size
 * @property string|null $reason
 * @property string|null $metadata
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @property-read ScanRecord $scan
 * @property-read Element $asset
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\records
 * @since 1.0.0
 */
class FindingRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::FINDINGS;
    }

    /**
     * Returns the parent scan record
     *
     * @return ActiveQueryInterface
     */
    public function getScan(): ActiveQueryInterface
    {
        return $this->hasOne(ScanRecord::class, ['id' => 'scanId']);
    }

    /**
     * Returns the associated element record
     *
     * @return ActiveQueryInterface
     */
    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'assetId']);
    }
}
