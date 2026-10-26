<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Exception\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\MigrationHelper;

class Version_1_2_0 extends AbstractMigration
{
    private string $conditionsTable = 'form_doi_actions_conditions';
    private string $logsTable       = 'form_doi_action_execution_logs';

    private Schema $schema;

    protected function isApplicable(Schema $schema): bool
    {
        $this->schema = $schema;

        try {
            $needsConditions = !$schema->hasTable($this->concatPrefix($this->conditionsTable));
            $needsLogs       = !$schema->hasTable($this->concatPrefix($this->logsTable));

            return $needsConditions || $needsLogs;
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $actionsTable     = MigrationHelper::requireTable($this->schema, $this->concatPrefix('form_doi_actions'));
        $submissionsTable = MigrationHelper::requireTable($this->schema, $this->concatPrefix('form_submissions'));
        $actionIdType     = MigrationHelper::getReferencedColumnType($actionsTable);
        $submissionIdType = MigrationHelper::getReferencedColumnType($submissionsTable);

        if (!$this->schema->hasTable($this->concatPrefix($this->conditionsTable))) {
            $conditionsTableName = $this->concatPrefix($this->conditionsTable);
            $actionsTableName    = $this->concatPrefix('form_doi_actions');

            $this->addSql(sprintf(
                "CREATE TABLE `%s` (
                    action_id %s NOT NULL,
                    conditions LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                    PRIMARY KEY (action_id)
                ) DEFAULT CHARACTER SET utf8mb4
                  COLLATE utf8mb4_unicode_ci
                  ENGINE=InnoDB
                  ROW_FORMAT=DYNAMIC;",
                $conditionsTableName,
                $actionIdType
            ));

            $this->addSql(sprintf(
                'ALTER TABLE `%s`
                    ADD CONSTRAINT `FK_409C900F9D32F035`
                    FOREIGN KEY (action_id) REFERENCES `%s` (id)
                    ON DELETE CASCADE;',
                $conditionsTableName,
                $actionsTableName
            ));
        }

        if (!$this->schema->hasTable($this->concatPrefix($this->logsTable))) {
            $logsTableName         = $this->concatPrefix($this->logsTable);
            $actionsTableName      = $this->concatPrefix('form_doi_actions');
            $submissionsTableName  = $this->concatPrefix('form_submissions');
            $doiSubmissionsTable   = MigrationHelper::requireTable($this->schema, $this->concatPrefix('form_doi_submissions'));
            $doiSubmissionIdType   = MigrationHelper::getReferencedColumnType($doiSubmissionsTable);

            $this->addSql(sprintf(
                'CREATE TABLE `%s` (
                    id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                    submission_id %s NOT NULL,
                    doi_submission_id %s NOT NULL,
                    action_id %s NOT NULL,
                    is_executed TINYINT(1) NOT NULL,
                    log_details LONGTEXT DEFAULT NULL,
                    date_added DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    INDEX IDX_548874C1E1FD4933 (submission_id),
                    INDEX IDX_548874C1D5F34997 (doi_submission_id),
                    INDEX IDX_548874C19D32F035 (action_id)
                ) DEFAULT CHARACTER SET utf8mb4
                  COLLATE utf8mb4_unicode_ci
                  ENGINE=InnoDB
                  ROW_FORMAT=DYNAMIC;',
                $logsTableName,
                $submissionIdType,
                $doiSubmissionIdType,
                $actionIdType
            ));

            $this->addSql(sprintf(
                'ALTER TABLE `%s`
                    ADD CONSTRAINT FK_548874C19D32F035
                        FOREIGN KEY (action_id) REFERENCES `%s` (id) ON DELETE CASCADE,
                    ADD CONSTRAINT FK_548874C1E1FD4933
                        FOREIGN KEY (submission_id) REFERENCES `%s` (id) ON DELETE CASCADE,
                    ADD CONSTRAINT FK_548874C1D5F34997
                        FOREIGN KEY (doi_submission_id) REFERENCES `%s` (id) ON DELETE CASCADE;',
                $logsTableName,
                $actionsTableName,
                $submissionsTableName,
                $this->concatPrefix('form_doi_submissions')
            ));
        }
    }
}
