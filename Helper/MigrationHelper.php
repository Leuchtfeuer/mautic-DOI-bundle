<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Helper;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BigIntType;

class MigrationHelper
{
    public static function getReferencedColumnType(Table $table, string $columnName = 'id'): string
    {
        if (!$table->hasColumn($columnName)) {
            throw new \InvalidArgumentException("Table '{$table->getName()}' does not have an '{$columnName}' column");
        }

        $idColumn   = $table->getColumn($columnName);
        $columnType = $idColumn->getType();
        $isUnsigned = $idColumn->getUnsigned();
        $baseType   = $columnType instanceof BigIntType ? 'BIGINT' : 'INT';

        return $isUnsigned ? "{$baseType} UNSIGNED" : $baseType;
    }

    /**
     * Fetch a table from the introspected schema, with a clear error when missing.
     */
    public static function requireTable(Schema $schema, string $tableName): Table
    {
        if ($schema->hasTable($tableName)) {
            return $schema->getTable($tableName);
        }

        throw new \RuntimeException(sprintf('Required table "%s" was not found in the database schema. Check that db_table_prefix / MAUTIC_TABLE_PREFIX matches the installed tables.', $tableName));
    }
}
