<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\LastDoiDateField;
use Psr\Log\LoggerInterface;

final readonly class ContactLastDoiDateUpdater
{
    public function __construct(
        private LeadModel $leadModel,
        private LoggerInterface $logger,
    ) {
    }

    public function updateFromSubmission(FormDoiSubmission $submission): void
    {
        if (!$submission->isConfirmed()) {
            return;
        }

        $lead = $submission->getLead();
        $date = $submission->getDateConfirmed();

        if (null === $lead || null === $date) {
            return;
        }

        try {
            $this->leadModel->setFieldValues(
                $lead,
                [LastDoiDateField::ALIAS => $date->format('Y-m-d H:i:s')],
                false,
                false
            );
            $this->leadModel->saveEntity($lead, false);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to update last_doi_date contact field', [
                'leadId'          => $lead->getId(),
                'doiSubmissionId' => $submission->getId(),
                'error'           => $exception->getMessage(),
            ]);
        }
    }
}
