<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryAction;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryMetadata;
use Psr\Log\LoggerInterface;

final class DoiVerificationHistoryRecorder
{
    public function __construct(
        private LeadEventLogRepository $leadEventLogRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function recordSuccess(FormDoiSubmission $submission, ?\DateTimeInterface $clickedAt = null): void
    {
        $this->record(
            $submission,
            DoiVerificationHistoryAction::SUCCESS,
            $clickedAt ?? $submission->getDateConfirmed() ?? new \DateTime()
        );
    }

    public function recordFailure(FormDoiSubmission $submission, ?\DateTimeInterface $clickedAt = null): void
    {
        $this->record($submission, DoiVerificationHistoryAction::FAILURE, $clickedAt ?? new \DateTime());
    }

    public function recordSkipped(FormDoiSubmission $submission, ?\DateTimeInterface $skippedAt = null): void
    {
        $this->record(
            $submission,
            DoiVerificationHistoryAction::SKIPPED,
            $skippedAt ?? $submission->getDateCreated()
        );
    }

    private function record(
        FormDoiSubmission $submission,
        DoiVerificationHistoryAction $action,
        \DateTimeInterface $clickedAt,
    ): void {
        try {
            $this->persistRecord($submission, $action, $clickedAt);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record DOI verification history', [
                'doiSubmissionId' => $submission->getId(),
                'action'          => $action->value,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function persistRecord(
        FormDoiSubmission $submission,
        DoiVerificationHistoryAction $action,
        \DateTimeInterface $clickedAt,
    ): void {
        $lead         = $submission->getLead();
        $submissionId = $submission->getId();

        if (null === $lead || null === $submissionId) {
            return;
        }

        if ($this->hasExistingLog($submissionId, $action)) {
            return;
        }

        $formName = $submission->getForm()?->getName() ?? '';

        $log = new LeadEventLog();
        $log->setLead($lead)
            ->setBundle(DoiVerificationHistoryMetadata::BUNDLE)
            ->setObject(DoiVerificationHistoryMetadata::OBJECT)
            ->setObjectId($submissionId)
            ->setAction($action->value)
            ->setDateAdded(\DateTime::createFromInterface($clickedAt))
            ->setProperties([
                'object_description' => $formName,
                'form_id'            => $submission->getForm()?->getId(),
                'doi_submission_id'  => $submissionId,
            ]);

        $this->leadEventLogRepository->saveEntity($log);
        $this->leadEventLogRepository->detachEntity($log);
    }

    private function hasExistingLog(int $submissionId, DoiVerificationHistoryAction $action): bool
    {
        $rows = $this->leadEventLogRepository->getSpecificRows(
            $submissionId,
            $action->value,
            ['limit' => 1, 'start' => 0],
            DoiVerificationHistoryMetadata::BUNDLE,
            DoiVerificationHistoryMetadata::OBJECT
        );

        return \count($rows) > 0;
    }
}
