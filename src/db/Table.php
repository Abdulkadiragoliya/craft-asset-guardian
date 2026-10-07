<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\db;

/**
 * Table constants for Asset Guardian
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\db
 * @since 1.0.0
 */
abstract class Table
{
    public const SCANS = '{{%assetguardian_scans}}';
    public const FINDINGS = '{{%assetguardian_findings}}';
    public const CLEANUP_OPERATIONS = '{{%assetguardian_cleanup_operations}}';
}
