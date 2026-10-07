<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\models;

use craft\base\Model;

/**
 * Asset Guardian Settings Model
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\models
 * @since 1.0.0
 */
class Settings extends Model
{
    /**
     * @var string[] List of volume handles to scan (empty array indicates all volumes)
     */
    public array $enabledVolumes = [];

    /**
     * @var int Age threshold in days to flag unused assets as candidates
     */
    public int $unusedAgeThresholdDays = 180;

    /**
     * @var int Large file threshold in bytes (default: 10 MB = 10,485,760 bytes)
     */
    public int $largeFileThresholdBytes = 10485760;

    /**
     * @var bool Whether missing alt text detection is enabled
     */
    public bool $scanMissingAlt = true;

    /**
     * @var bool Always move to Craft Trash by default instead of permanent deletion
     */
    public bool $safeTrashOnly = true;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['unusedAgeThresholdDays', 'largeFileThresholdBytes'], 'integer'],
            [['unusedAgeThresholdDays'], 'default', 'value' => 180],
            [['largeFileThresholdBytes'], 'default', 'value' => 10485760],
            [['scanMissingAlt', 'safeTrashOnly'], 'boolean'],
            [['scanMissingAlt', 'safeTrashOnly'], 'default', 'value' => true],
            [['enabledVolumes'], 'each', 'rule' => ['string']],
        ];
    }
}
