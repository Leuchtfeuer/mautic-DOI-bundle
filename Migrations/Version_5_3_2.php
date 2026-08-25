<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Exception\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_5_3_2 extends AbstractMigration
{
    private const TABLE = 'form_doi_submissions';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $tableName = $this->concatPrefix(self::TABLE);

            return $schema->hasTable($tableName)
                && !$schema->getTable($tableName)->hasColumn('submitted_consent_snapshot');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $this->addSql(sprintf(
            "ALTER TABLE `%s` ADD `submitted_consent_snapshot` JSON DEFAULT NULL COMMENT '(DC2Type:json)'",
            $this->concatPrefix(self::TABLE)
        ));
    }
}
