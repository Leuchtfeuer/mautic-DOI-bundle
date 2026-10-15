<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Exception\SchemaException;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\AbstractPrefixedMigration;

class Version_1_3_0 extends AbstractPrefixedMigration
{
    private string $formDoiConfigTable = 'form_doi_config';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $tableName = $this->concatPrefix($this->formDoiConfigTable);

            if (!$schema->hasTable($tableName)) {
                return false;
            }

            $table = $schema->getTable($tableName);

            return !$table->hasColumn('delete_after_timeout');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $this->addSql("ALTER TABLE `{$this->concatPrefix($this->formDoiConfigTable)}` ADD delete_after_timeout TINYINT(1) DEFAULT 0 NOT NULL");
    }
}
