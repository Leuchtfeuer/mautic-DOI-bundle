<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Mautic\CoreBundle\Exception\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\MigrationHelper;

class Version_5_3_1 extends AbstractMigration
{
    private const TABLE = 'form_doi_submissions';

    private Schema $schema;

    protected function isApplicable(Schema $schema): bool
    {
        $this->schema = $schema;

        try {
            $tableName = $this->concatPrefix(self::TABLE);
            if (!$schema->hasTable($tableName)) {
                return false;
            }

            $table = $schema->getTable($tableName);

            return $this->relationNeedsMigration($table, 'form_id')
                || $this->relationNeedsMigration($table, 'form_submission_id');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->schema->getTable($this->concatPrefix(self::TABLE));

        $this->migrateRelation($table, 'form_id', 'forms', 'FK_FORM_DOI_SUBMISSIONS_FORM');
        $this->migrateRelation($table, 'form_submission_id', 'form_submissions', 'FK_FORM_DOI_SUBMISSIONS_SUBMISSION');
    }

    private function relationNeedsMigration(Table $table, string $columnName): bool
    {
        if (!$table->hasColumn($columnName) || $table->getColumn($columnName)->getNotnull()) {
            return true;
        }

        $foreignKey = $this->findForeignKey($table, $columnName);

        return null === $foreignKey || 'SET NULL' !== strtoupper((string) ($foreignKey->getOptions()['onDelete'] ?? ''));
    }

    private function migrateRelation(Table $table, string $columnName, string $referencedTable, string $fallbackConstraintName): void
    {
        if (!$table->hasColumn($columnName)) {
            return;
        }

        $foreignKey       = $this->findForeignKey($table, $columnName);
        $hasSetNullAction = null !== $foreignKey
            && 'SET NULL' === strtoupper((string) ($foreignKey->getOptions()['onDelete'] ?? ''));
        $constraintName   = $foreignKey?->getName() ?? $fallbackConstraintName;
        $tableName        = $table->getName();

        if (null !== $foreignKey && !$hasSetNullAction) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName));
        }

        if ($table->getColumn($columnName)->getNotnull()) {
            $referencedTableSchema = $this->schema->getTable($this->concatPrefix($referencedTable));
            $columnType            = MigrationHelper::getReferencedColumnType($referencedTableSchema);
            $this->addSql(sprintf('ALTER TABLE `%s` MODIFY `%s` %s DEFAULT NULL', $tableName, $columnName, $columnType));
        }

        if (!$hasSetNullAction) {
            $this->addSql(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE SET NULL',
                $tableName,
                $constraintName,
                $columnName,
                $this->concatPrefix($referencedTable)
            ));
        }
    }

    private function findForeignKey(Table $table, string $columnName): ?ForeignKeyConstraint
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if ([$columnName] === $foreignKey->getLocalColumns()) {
                return $foreignKey;
            }
        }

        return null;
    }
}
