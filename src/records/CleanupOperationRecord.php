<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\records;

use craft\db\ActiveRecord;
use craft\records\User;
use abdulkadiragoliya\assetguardian\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Cleanup Operation Record
 *
 * @property int $id
 * @property int|null $userId
 * @property string $status
 * @property int $assetCount
 * @property int $totalBytes
 * @property string $operation
 * @property string|null $metadata
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @property-read User|null $user
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\records
 * @since 1.0.0
 */
class CleanupOperationRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::CLEANUP_OPERATIONS;
    }

    /**
     * Returns the user who initiated this cleanup
     *
     * @return ActiveQueryInterface
     */
    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
