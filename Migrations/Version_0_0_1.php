<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Exception\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\MigrationHelper;

class Version_0_0_1 extends AbstractMigration
{
    private string $table = 'form_doi_actions';
    private Schema $schema;

    protected function isApplicable(Schema $schema): bool
    {
        $this->schema = $schema;

        try {
            return !$schema->hasTable($this->concatPrefix($this->table));
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $formsTable        = MigrationHelper::requireTable($this->schema, $this->concatPrefix('forms'));
        $formsIdColumnType = MigrationHelper::getReferencedColumnType($formsTable);
        $formsTableName    = $this->concatPrefix('forms');
        $actionsTableName  = $this->concatPrefix($this->table);

        $this->addSql("CREATE TABLE `{$actionsTableName}`
(
    id           INT AUTO_INCREMENT NOT NULL,
    form_id      {$formsIdColumnType}       NOT NULL,
    name         VARCHAR(255)       NOT NULL,
    description  VARCHAR(255) DEFAULT NULL,
    type         VARCHAR(50)        NOT NULL,
    action_order INT                NOT NULL,
    properties   LONGTEXT           NOT NULL COMMENT '(DC2Type:array)',
    INDEX IDX_B8EF63BE5FF69B7D (form_id),
    INDEX form_doi_action_type_search (type),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4
  COLLATE `utf8mb4_unicode_ci`
  ENGINE = InnoDB
  ROW_FORMAT = DYNAMIC;");

        $this->addSql("ALTER TABLE `{$actionsTableName}` ADD CONSTRAINT FK_B8EF63BE5FF69B7D FOREIGN KEY (form_id) REFERENCES `{$formsTableName}` (id) ON DELETE CASCADE;");
    }
}
