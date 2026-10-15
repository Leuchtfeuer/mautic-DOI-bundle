<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\DTO\DoiTokenData;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use Psr\Log\LoggerInterface;

class FollowUpSender
{
    public function __construct(
        private readonly EmailModel $emailModel,
        private readonly DoiConfigManager $doiConfigManager,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LeadRepository $leadRepository,
        private readonly DoiTokenParser $doiTokenParser,
    ) {
    }

    public function send(FormDoiSubmission $submission): bool
    {
        if ($submission->hasFollowupSent() || !$submission->isPending() || null !== $submission->getDateConfirmed()) {
            return false;
        }

        $form   = $submission->getForm();
        $config = $this->doiConfigManager->getFormDoiConfig($form);

        if (null === $config || !$config->isEnabled()) {
            return false;
        }

        $emailEntity = $config->getFollowUpEmail();
        if (null === $emailEntity || !$emailEntity->isPublished()) {
            return false;
        }

        $lead = $submission->getLead();
        if (null === $lead || !$lead->getEmail()) {
            return false;
        }

        $tokenData    = new DoiTokenData($form->getId(), $submission->getHash());
        $encodedToken = $this->doiTokenParser->encode($tokenData);
        $doiLink      = $this->emailModel->buildUrl('mautic_doi_email_verify_action', [
            'token' => $encodedToken,
        ]);
        $tokens = ['{doi_link}' => $doiLink];

        $fields = $lead->getFields();
        if ([] === $fields) {
            $this->hydrateCustomFieldData($lead);
        }
        $contactFields = $lead->getProfileFields();

        $result = $this->emailModel->sendEmail($emailEntity, $contactFields, [
            'source'        => ['form', $form->getId()],
            'tokens'        => $tokens,
            'return_errors' => true,
            'ignoreDNC'     => true,
            'email_type'    => MailHelper::EMAIL_TYPE_TRANSACTIONAL,
        ]);

        if (true !== $result) {
            $this->logger->error('DOI follow-up send failed', [
                'submission_id' => $submission->getId(),
                'email'         => $submission->getEmail(),
                'errors'        => $result,
            ]);

            return false;
        }

        $submission->markFollowupSent();
        $this->em->persist($submission);
        $this->em->flush();

        return true;
    }

    private function hydrateCustomFieldData(?Lead $lead = null): void
    {
        if (null === $lead) {
            return;
        }

        // Hydrate fields with custom field data
        $fields = $this->leadRepository->getFieldValues($lead->getId());
        $lead->setFields($fields);
    }
}
