<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\db\pgsql;

use Craft;
use craft\db\Connection;
use craft\db\ExpressionBuilder;
use craft\db\ExpressionInterface;
use craft\db\TableSchema;
use craft\helpers\App;
use mikehaertl\shellcommand\Command as ShellCommand;
use yii\db\Exception;

/**
 * @inheritdoc
 * @method TableSchema|null getTableSchema($name, $refresh = false) Obtains the schema information for the named table.
 * @property Connection $db
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class Schema extends \yii\db\pgsql\Schema
{
    /**
     * @see getBackupFormat()
     * @see setBackupFormat()
     */
    private ?string $backupFormat = null;
    /**
     * @see getRestoreFormat()
     * @see setRestoreFormat()
     */
    private ?string $restoreFormat = null;

    /**
     * @var int The maximum length that objects' names can be.
     */
    public int $maxObjectNameLength = 63;

    /**
     * Returns whether a table supports 4-byte characters.
     *
     * @param string $table The table to check
     * @return bool
     * @since 5.0.0
     */
    public function supportsMb4(string $table): bool
    {
        return true;
    }

    /**
     * Creates a query builder for the database.
     *
     * This method may be overridden by child classes to create a DBMS-specific query builder.
     *
     * @return QueryBuilder query builder instance
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->db, [
            'expressionBuilders' => [
                ExpressionInterface::class => ExpressionBuilder::class,
            ],
            'separator' => "\n",
        ]);
    }

    /**
     * Quotes a database name for use in a query.
     *
     * @param string $name
     * @return string
     * @deprecated in 5.4.0
     */
    public function quoteDatabaseName(string $name): string
    {
        return '"' . $name . '"';
    }

    /**
     * Releases an existing savepoint.
     *
     * @param string $name The savepoint name.
     * @throws Exception
     */
    public function releaseSavepoint($name): void
    {
        try {
            parent::releaseSavepoint($name);
        } catch (Exception $e) {
            // Specifically look for a "No such savepoint" error.
            if (in_array($e->getCode(), ['25P01', '3B001'], true)) {
                Craft::warning('Tried to release a savepoint, but it does not exist: ' . $e->getMessage(), __METHOD__);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Rolls back to a previously created savepoint.
     *
     * @param string $name The savepoint name.
     * @throws Exception
     */
    public function rollBackSavepoint($name): void
    {
        try {
            parent::rollBackSavepoint($name);
        } catch (Exception $e) {
            // Specifically look for a "No such savepoint" error.
            if ($e->getCode() == 3 && isset($e->errorInfo[0]) && isset($e->errorInfo[1]) && $e->errorInfo[0] === '3B001' && $e->errorInfo[1] == 7) {
                Craft::warning('Tried to roll back a savepoint, but it does not exist: ' . $e->getMessage(), __METHOD__);
            } else {
                throw $e;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getLastInsertID($sequenceName = ''): string
    {
        if ($sequenceName !== '') {
            if (!str_contains($sequenceName, '.')) {
                $sequenceName = $this->defaultSchema . '.' . $this->getRawTableName($sequenceName);
            }
            $sequenceName .= '_id_seq';
        }

        return parent::getLastInsertID($sequenceName);
    }

    /**
     * Returns the default backup command to execute.
     *
     * @param string[]|null $ignoreTables The table names whose data should be excluded from the backup
     * @return string The command to execute
     */
    public function getDefaultBackupCommand(?array $ignoreTables = null): string
    {
        $command = (new ShellCommand('pg_dump'))
            ->addArg('--dbname=', '{database}')
            ->addArg('--host=', '{server}')
            ->addArg('--port=', '{port}')
            ->addArg('--username=', '{user}')
            ->addArg('--if-exists')
            ->addArg('--clean')
            ->addArg('--no-owner')
            ->addArg('--no-privileges')
            ->addArg('--no-acl')
            ->addArg('--file=', '{file}')
            ->addArg('--schema=', '{schema}');

        $ignoreTables ??= Craft::$app->getDb()->getIgnoredBackupTables();
        $format = $this->getBackupFormat();
        $commandFromConfig = Craft::$app->getConfig()->getGeneral()->backupCommand;

        foreach ($ignoreTables as $table) {
            $table = $this->getRawTableName($table);
            $command->addArg('--exclude-table-data', "{schema}.$table");
        }

        if ($format) {
            $command->addArg('--format=', $format);
        }

        if ($commandFromConfig instanceof \Closure) {
            $command = $commandFromConfig($command);
        }

        return $this->_pgpasswordCommand() . $command->getExecCommand();
    }

    /**
     * Returns the default database restore command to execute.
     *
     * @return string The command to execute
     */
    public function getDefaultRestoreCommand(): string
    {
        $command = (new ShellCommand($this->usePgRestore() ? 'pg_restore' : 'psql'))
            ->addArg('--dbname=', '{database}')
            ->addArg('--host=', '{server}')
            ->addArg('--port=', '{port}')
            ->addArg('--username=', '{user}')
            ->addArg('--no-password');

        if ($this->usePgRestore()) {
            $command
                ->addArg('--clean')
                ->addArg('--if-exists')
                ->addArg('--no-owner')
                ->addArg('--no-acl')
                ->addArg('--schema=', '{schema}')
                ->addArg('--single-transaction')

                // If we're using pg_restore, we can't use STDIN, as it may be a directory
                ->addArg('{file}');
        }

        $commandFromConfig = Craft::$app->getConfig()->getGeneral()->restoreCommand;

        if ($commandFromConfig instanceof \Closure) {
            $command = $commandFromConfig($command);
        }

        return $this->_pgpasswordCommand()
            . $command->getExecCommand()
            . ($this->usePgRestore() ? '' : ' < "{file}"');
    }

    /**
     * Returns all indexes for the given table. Each array element is of the following structure:
     *
     * ```php
     * [
     *     'IndexName' => [
     *         'columns' => ['col1' [, ...]],
     *         'unique' => false
     *     ],
     * ]
     * ```
     *
     * @param string $tableName The name of the table to get the indexes for.
     * @return array All indexes for the given table.
     */
    public function findIndexes(string $tableName): array
    {
        $tableName = Craft::$app->getDb()->getSchema()->getRawTableName($tableName);
        $table = Craft::$app->getDb()->getSchema()->getTableSchema($tableName);
        $indexes = [];

        $rows = $this->getIndexInformation($table);

        foreach ($rows as $row) {
            $column = $row['columnname'];

            if (!empty($column) && $column[0] === '"') {
                // postgres will quote names that are not lowercase-only
                // https://github.com/yiisoft/yii2/issues/10613
                $column = substr($column, 1, -1);
            }

            $indexes[$row['indexname']]['columns'][] = $column;
            $indexes[$row['indexname']]['unique'] = (bool)$row['isunique'];
        }

        return $indexes;
    }

    /**
     * Loads the metadata for the specified table.
     *
     * @param string $name table name
     * @return TableSchema|null driver dependent table metadata. Null if the table does not exist.
     */
    public function loadTableSchema($name): ?TableSchema
    {
        $table = new TableSchema();
        $this->resolveTableNames($table, $name);
        if ($this->findColumns($table)) {
            $this->findConstraints($table);

            return $table;
        }

        return null;
    }

    /**
     * Whether `pg_restore` should be used for the restore command.
     *
     * @return bool
     * @since 5.1.0
     */
    public function usePgRestore(): bool
    {
        return isset($this->restoreFormat) && $this->restoreFormat !== 'plain';
    }

    /**
     * Collects extra foreign key information details for the given table.
     *
     * @param TableSchema $table the table metadata
     */
    protected function findConstraints($table): void
    {
        parent::findConstraints($table);

        // Modified from parent to get extended FK information.
        $tableName = $this->quoteValue($table->name);
        $tableSchema = $this->quoteValue($table->schemaName);

        $sql = <<<SQL
SELECT
    ct.conname AS constraint_name,
    a.attname AS column_name,
    fc.relname AS foreign_table_name,
    fns.nspname AS foreign_table_schema,
    fa.attname AS foreign_column_name,
    ct.confupdtype AS update_type,
    ct.confdeltype AS delete_type
from
    (SELECT ct.conname, ct.conrelid, ct.confrelid, ct.conkey, ct.contype, ct.confkey, generate_subscripts(ct.conkey, 1) AS s, ct.confupdtype, ct.confdeltype
       FROM pg_constraint ct
    ) AS ct
    INNER JOIN pg_class c ON c.oid=ct.conrelid
    INNER JOIN pg_namespace ns ON c.relnamespace=ns.oid
    INNER JOIN pg_attribute a ON a.attrelid=ct.conrelid AND a.attnum = ct.conkey[ct.s]
    LEFT JOIN pg_class fc ON fc.oid=ct.confrelid
    LEFT JOIN pg_namespace fns ON fc.relnamespace=fns.oid
    LEFT JOIN pg_attribute fa ON fa.attrelid=ct.confrelid AND fa.attnum = ct.confkey[ct.s]
WHERE
    ct.contype='f'
    AND c.relname=$tableName
    AND ns.nspname=$tableSchema
ORDER BY 
    fns.nspname, fc.relname, a.attnum
SQL;

        $extendedConstraints = $this->db->createCommand($sql)->queryAll();

        foreach ($extendedConstraints as $key => $extendedConstraint) {
            // Find out what to do on update.
            $updateAction = match ($extendedConstraint['update_type']) {
                'a' => 'NO ACTION',
                'r' => 'RESTRICT',
                'c' => 'CASCADE',
                'n' => 'SET NULL',
                default => 'DEFAULT',
            };

            // Find out what to do on update.
            $deleteAction = match ($extendedConstraint['delete_type']) {
                'a' => 'NO ACTION',
                'r' => 'RESTRICT',
                'c' => 'CASCADE',
                'n' => 'SET NULL',
                default => 'DEFAULT',
            };

            $table->addExtendedForeignKey($key, [
                'updateType' => $updateAction,
                'deleteType' => $deleteAction,
            ]);
        }
    }

    /**
     * Gets information about given table indexes.
     *
     * @param TableSchema $table The table metadata
     * @return array Index and column names
     */
    protected function getIndexInformation(TableSchema $table): array
    {
        $sql = 'SELECT
    i.relname as indexname,
    pg_get_indexdef(idx.indexrelid, k + 1, TRUE) AS columnname,
    indisunique as isunique
FROM (
  SELECT *, generate_subscripts(indkey, 1) AS k
  FROM pg_index
) idx
INNER JOIN pg_class i ON i.oid = idx.indexrelid
INNER JOIN pg_class c ON c.oid = idx.indrelid
INNER JOIN pg_namespace ns ON c.relnamespace = ns.oid
WHERE c.relname = :tableName AND ns.nspname = :schemaName
AND idx.indisprimary = FALSE 
ORDER BY i.relname, k';

        return $this->db->createCommand($sql, [
            ':schemaName' => $table->schemaName,
            ':tableName' => $table->name,
        ])->queryAll();
    }

    /**
     * Returns the PGPASSWORD command for backup/restore actions.
     *
     * @return string
     */
    private function _pgpasswordCommand(): string
    {
        return App::isWindows() ? "set PGPASSWORD='{password}' && " : "PGPASSWORD='{password}' ";
    }

    /**
     * Returns the backup format that should be used (`custom`, `directory`, `tar`, or `plain`).
     *
     * @return string|null
     * @since 5.2.0
     */
    public function getBackupFormat(): ?string
    {
        return $this->backupFormat ?? Craft::$app->getConfig()->getGeneral()->backupCommandFormat;
    }

    /**
     * Sets the backup format that should be used (`custom`, `directory`, `tar`, or `plain`).
     *
     * @param string|null $backupFormat
     * @since 5.2.0
     */
    public function setBackupFormat(?string $backupFormat): void
    {
        $this->backupFormat = $backupFormat;
    }

    /**
     * Returns the restore format that should be used (`custom`, `directory`, `tar`, or `plain`).
     *
     * @return string|null
     * @since 5.2.0
     */
    public function getRestoreFormat(): ?string
    {
        return $this->restoreFormat;
    }

    /**
     * Sets the restore format that should be used (`custom`, `directory`, `tar`, or `plain`).
     *
     * @param string|null $restoreFormat
     * @since 5.2.0
     */
    public function setRestoreFormat(?string $restoreFormat): void
    {
        $this->restoreFormat = $restoreFormat;
    }
}
