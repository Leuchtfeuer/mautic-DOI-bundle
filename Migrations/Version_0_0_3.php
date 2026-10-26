<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Exception\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\MigrationHelper;

class Version_0_0_3 extends AbstractMigration
{
    private string $formDoiSubmissionsTable = 'form_doi_submissions';
    private Schema $schema;

    protected function isApplicable(Schema $schema): bool
    {
        $this->schema = $schema;
        try {
            return !$schema->hasTable($this->concatPrefix($this->formDoiSubmissionsTable));
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $formsTable                  = MigrationHelper::requireTable($this->schema, $this->concatPrefix('forms'));
        $formSubmissionsTable        = MigrationHelper::requireTable($this->schema, $this->concatPrefix('form_submissions'));
        $leadsTable                  = MigrationHelper::requireTable($this->schema, $this->concatPrefix('leads'));
        $formsIdColumnType           = MigrationHelper::getReferencedColumnType($formsTable);
        $formSubmissionsIdColumnType = MigrationHelper::getReferencedColumnType($formSubmissionsTable);
        $leadsIdColumnType           = MigrationHelper::getReferencedColumnType($leadsTable);

        $this->addSql("CREATE TABLE `{$this->concatPrefix($this->formDoiSubmissionsTable)}`
(
    id                 INT AUTO_INCREMENT NOT NULL,
    form_submission_id {$formSubmissionsIdColumnType}    NOT NULL,
    form_id            {$formsIdColumnType}      NOT NULL,
    lead_id            {$leadsIdColumnType} DEFAULT NULL,
    email              VARCHAR(255)       NOT NULL,
    hash               VARCHAR(255)       NOT NULL,
    date_created       DATETIME           NOT NULL,
    date_confirmed     DATETIME        DEFAULT NULL,
    status             VARCHAR(20)        NOT NULL,
    UNIQUE INDEX UNIQ_E6D188B1D1B862B8 (hash),
    INDEX IDX_E6D188B1422B0E0C (form_submission_id),
    INDEX IDX_E6D188B15FF69B7D (form_id),
    INDEX IDX_E6D188B155458D (lead_id),
    INDEX form_doi_submission_hash_search (hash),
    INDEX form_doi_submission_status_search (status),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4
  COLLATE `utf8mb4_unicode_ci`
  ENGINE = InnoDB
  ROW_FORMAT = DYNAMIC;");

        $this->addSql("ALTER TABLE `{$this->concatPrefix($this->formDoiSubmissionsTable)}` ADD CONSTRAINT FK_E6D188B1422B0E0C FOREIGN KEY (form_submission_id) REFERENCES `{$this->concatPrefix('form_submissions')}` (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE `{$this->concatPrefix($this->formDoiSubmissionsTable)}` ADD CONSTRAINT FK_E6D188B15FF69B7D FOREIGN KEY (form_id) REFERENCES `{$this->concatPrefix('forms')}` (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE `{$this->concatPrefix($this->formDoiSubmissionsTable)}` ADD CONSTRAINT FK_E6D188B155458D FOREIGN KEY (lead_id) REFERENCES `{$this->concatPrefix('leads')}` (id) ON DELETE CASCADE");
    }
}
