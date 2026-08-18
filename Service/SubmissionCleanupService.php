<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Entity\SubmissionRepository;
use Mautic\FormBundle\Helper\FormUploader;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfigRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmissionRepository;
use Psr\Log\LoggerInterface;

class SubmissionCleanupService
{
    /**
     * The maximum difference in seconds allowed between Contact create date
     * and Submission create date to consider them "created together".
     */
    private const DATE_MATCH_DELTA = 2;

    /**
     * Cache for table existence checks to avoid repeated schema queries.
     *
     * @var array<string, bool>
     */
    private array $tableExistsCache = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FormDoiConfigRepository $configRepository,
        private FormDoiSubmissionRepository $submissionRepository,
        private SubmissionRepository $coreSubmissionRepository,
        private FormUploader $formUploader,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get the DBAL connection from the EntityManager to ensure transactional consistency.
     */
    private function getConnection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Get all DOI configs that have cleanup enabled.
     *
     * @return list<FormDoiConfig>
     */
    public function getConfigsWithCleanupEnabled(): array
    {
        return $this->configRepository->findConfigsWithCleanupEnabled();
    }

    /**
     * Find timed-out submissions eligible for cleanup for a given config.
     *
     * All submissions with STATUS_TIMEOUT are returned for immediate deletion
     * (no grace period - the global DOI link timeout already controls when submissions time out).
     *
     * @return list<FormDoiSubmission>
     */
    public function findSubmissionsForCleanup(FormDoiConfig $config, int $limit): array
    {
        $form = $config->getForm();
        if (null === $form) {
            return [];
        }

        return $this->submissionRepository->findTimedOutSubmissionsForCleanup(
            $form->getId(),
            $limit
        );
    }

    /**
     * Delete a single DOI submission and all related data.
     *
     * If the contact was created by this submission (new contact), the contact
     * is also deleted. If the contact existed before the submission (previously known),
     * only the submission is deleted and the contact remains.
     *
     * @return bool True if deleted, false if skipped
     */
    public function deleteSubmission(FormDoiSubmission $doiSubmission): bool
    {
        $coreSubmission = $doiSubmission->getFormSubmission();
        if (null === $coreSubmission) {
            $this->logger->warning('DOI submission has no linked core submission', [
                'doiSubmissionId' => $doiSubmission->getId(),
            ]);

            return false;
        }

        $form = $doiSubmission->getForm();
        if (null === $form) {
            $this->logger->warning('DOI submission has no linked form', [
                'doiSubmissionId' => $doiSubmission->getId(),
            ]);

            return false;
        }

        $formId    = $form->getId();
        $formAlias = $form->getAlias();
        $conn      = $this->getConnection();

        // Determine if contact should be deleted (new contact created by this submission)
        $lead                = $doiSubmission->getLead();
        $shouldDeleteContact = $this->shouldDeleteContact($lead, $doiSubmission);

        // Reload submission via core repository to hydrate results from form_results table.
        // Results are stored in a separate table and not loaded when the Submission
        // is loaded via ORM relationships (see SubmissionRepository::getEntity()).
        $coreSubmissionWithResults = $this->coreSubmissionRepository->getEntity($coreSubmission->getId());
        if (null !== $coreSubmissionWithResults) {
            $this->deleteUploadedFiles($coreSubmissionWithResults);
        }

        try {
            $conn->beginTransaction();

            // delete from form_results table (DBAL - no ORM entity)
            $this->deleteFormResultsRow($formId, $formAlias, $coreSubmission);

            // Detach/Remove the DOI Submission explicitly first to avoid "new entity found" cascade errors
            // when flushing the core submission removal.
            $this->entityManager->remove($doiSubmission);

            // delete core Submission entity
            $this->entityManager->remove($coreSubmission);

            // Delete contact if it was created by this submission
            if ($shouldDeleteContact && null !== $lead) {
                $this->entityManager->remove($lead);
            }

            $this->entityManager->flush();

            $conn->commit();

            return true;
        } catch (\Throwable $e) {
            try {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
            } catch (\Throwable $rollbackError) {
                $this->logger->error('Rollback failed', [
                    'error' => $rollbackError->getMessage(),
                ]);
            }

            $this->logger->error('Failed to delete DOI submission', [
                'doiSubmissionId'  => $doiSubmission->getId(),
                'coreSubmissionId' => $coreSubmission->getId(),
                'error'            => $e->getMessage(),
            ]);

            // Clear EntityManager to prevent inconsistent state
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            return false;
        }
    }

    /**
     * Determine if a contact should be deleted along with the submission.
     *
     * A contact is considered "new" (created by this submission) if the contact's
     * dateIdentified matches the submission's dateCreated. If the contact existed before
     * the submission was created, it is considered "previously known" and should not be deleted.
     *
     * Additionally, if the contact has other active DOI submissions (pending or confirmed),
     * or has been active after the submission was created, it should not be deleted.
     */
    private function shouldDeleteContact(?Lead $lead, FormDoiSubmission $doiSubmission): bool
    {
        if (null === $lead) {
            return false;
        }

        $leadId       = $lead->getId();
        $submissionId = $doiSubmission->getId();

        if (0 === $leadId || null === $submissionId) {
            return false;
        }

        // Don't delete if contact has other active DOI submissions (pending or confirmed)
        if ($this->submissionRepository->hasOtherActiveSubmissionsForLead($leadId, $submissionId)) {
            return false;
        }

        $contactDateIdentified = $lead->getDateIdentified();
        $submissionDateCreated = $doiSubmission->getDateCreated();

        if (null === $contactDateIdentified) {
            return false;
        }

        // Contact is considered "new" if it was identified at the same time as the submission
        if (abs($contactDateIdentified->getTimestamp() - $submissionDateCreated->getTimestamp()) > self::DATE_MATCH_DELTA) {
            return false;
        }

        // Don't delete if contact has been active after the submission was created
        $lastActive = $lead->getLastActive();
        if (null !== $lastActive && ($lastActive->getTimestamp() - $submissionDateCreated->getTimestamp()) > self::DATE_MATCH_DELTA) {
            return false;
        }

        return true;
    }

    /**
     * Clear the EntityManager to free memory during batch processing.
     */
    public function clearEntityManager(): void
    {
        $this->entityManager->clear();
    }

    private function deleteFormResultsRow(int $formId, string $formAlias, Submission $submission): void
    {
        $submissionId = $submission->getId();

        // Guard against null submission ID to prevent unintended deletions
        if ($submissionId < 1) {
            $this->logger->error('Core submission has no ID; skipping form_results deletion', [
                'formId'    => $formId,
                'formAlias' => $formAlias,
            ]);

            return;
        }

        // Validate form alias contains only safe characters (alphanumeric and underscore)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $formAlias)) {
            $this->logger->error('Invalid form alias detected, skipping form_results deletion', [
                'formId'    => $formId,
                'formAlias' => $formAlias,
            ]);

            return;
        }

        $tableName = MAUTIC_TABLE_PREFIX.'form_results_'.$formId.'_'.$formAlias;
        $conn      = $this->getConnection();

        // Cache table existence check to avoid repeated schema queries
        if (!isset($this->tableExistsCache[$tableName])) {
            $schemaManager                      = $conn->createSchemaManager();
            $this->tableExistsCache[$tableName] = $schemaManager->tablesExist([$tableName]);
        }

        if (!$this->tableExistsCache[$tableName]) {
            return;
        }

        $conn->delete($tableName, ['submission_id' => $submissionId]);
    }

    private function deleteUploadedFiles(Submission $submission): void
    {
        try {
            $this->formUploader->deleteUploadedFiles($submission);
        } catch (\Throwable $e) {
            // Log but don't fail the whole operation if file deletion fails
            $this->logger->warning('Failed to delete uploaded files for submission', [
                'submissionId' => $submission->getId(),
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
