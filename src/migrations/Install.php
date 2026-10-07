<?php
declare(strict_types=1);

namespace abdulkadiragoliya\assetguardian\migrations;

use craft\db\Migration;
use abdulkadiragoliya\assetguardian\db\Table;

/**
 * Install Migration for Asset Guardian
 *
 * @author Abdulkadir Agoliya
 * @package abdulkadiragoliya\assetguardian\migrations
 * @since 1.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropForeignKeys();
        $this->dropTables();

        return true;
    }

    /**
     * Creates the plugin database tables
     */
    protected function createTables(): void
    {
        // assetguardian_scans
        if (!$this->db->tableExists(Table::SCANS)) {
            $this->createTable(Table::SCANS, [
                'id' => $this->primaryKey(),
                'status' => $this->string(32)->notNull()->defaultValue('pending'),
                'startedAt' => $this->dateTime(),
                'completedAt' => $this->dateTime(),
                'totalAssets' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'unusedCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'duplicateCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'largeFileCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'missingAltCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'potentialRecovery' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'healthScore' => $this->integer()->unsigned()->notNull()->defaultValue(100),
                'errorMessage' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        // assetguardian_findings
        if (!$this->db->tableExists(Table::FINDINGS)) {
            $this->createTable(Table::FINDINGS, [
                'id' => $this->primaryKey(),
                'scanId' => $this->integer()->notNull(),
                'assetId' => $this->integer()->notNull(),
                'type' => $this->string(32)->notNull(),
                'risk' => $this->string(32)->notNull()->defaultValue('low'),
                'size' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'reason' => $this->text(),
                'metadata' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        // assetguardian_cleanup_operations
        if (!$this->db->tableExists(Table::CLEANUP_OPERATIONS)) {
            $this->createTable(Table::CLEANUP_OPERATIONS, [
                'id' => $this->primaryKey(),
                'userId' => $this->integer(),
                'status' => $this->string(32)->notNull()->defaultValue('completed'),
                'assetCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'totalBytes' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'operation' => $this->string(64)->notNull()->defaultValue('trash'),
                'metadata' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }
    }

    /**
     * Creates indexes
     */
    protected function createIndexes(): void
    {
        // Scans indexes
        $this->createIndex(null, Table::SCANS, 'status');
        $this->createIndex(null, Table::SCANS, 'dateCreated');

        // Findings indexes
        $this->createIndex(null, Table::FINDINGS, ['scanId', 'type']);
        $this->createIndex(null, Table::FINDINGS, ['scanId', 'risk']);
        $this->createIndex(null, Table::FINDINGS, 'assetId');
        $this->createIndex(null, Table::FINDINGS, 'type');

        // Cleanup indexes
        $this->createIndex(null, Table::CLEANUP_OPERATIONS, 'status');
        $this->createIndex(null, Table::CLEANUP_OPERATIONS, 'dateCreated');
    }

    /**
     * Adds foreign keys
     */
    protected function addForeignKeys(): void
    {
        // Findings -> Scans
        $this->addForeignKey(
            null,
            Table::FINDINGS,
            'scanId',
            Table::SCANS,
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Findings -> Elements
        $this->addForeignKey(
            null,
            Table::FINDINGS,
            'assetId',
            '{{%elements}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Cleanup -> Users
        $this->addForeignKey(
            null,
            Table::CLEANUP_OPERATIONS,
            'userId',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    /**
     * Drops foreign keys
     */
    protected function dropForeignKeys(): void
    {
        // Safe down drops tables directly, cascading will clean them
    }

    /**
     * Drops tables
     */
    protected function dropTables(): void
    {
        $this->dropTableIfExists(Table::FINDINGS);
        $this->dropTableIfExists(Table::SCANS);
        $this->dropTableIfExists(Table::CLEANUP_OPERATIONS);
    }
}
