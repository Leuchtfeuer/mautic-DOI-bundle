<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerDoiBundle\DTO\DoiTokenData;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiSubmissionManager;
use Psr\Log\LoggerInterface;

class VerificationEmailSender
{
    public function __construct(
        private EmailModel $emailModel,
        private DoiConfigManager $doiConfigManager,
        private DoiHashGenerator $doiHashGenerator,
        private FormDoiSubmissionManager $doiSubmissionManager,
        private DoiTokenParser $doiTokenParser,
        private LoggerInterface $logger,
        private ConsentSnapshotBuilder $consentSnapshotBuilder,
    ) {
    }

    public function send(SubmissionEvent $event): bool
    {
        $formSubmission = $event->getSubmission();
        $form           = $formSubmission->getForm();
        $doiConfig      = $this->doiConfigManager->getFormDoiConfig($form);
        $contact        = $event->getLead();

        if (null === $contact || !$contact->getEmail()) {
            return false;
        }

        if (null === $doiConfig || !$doiConfig->isEnabled()) {
            return false;
        }

        $doiSubmission = $this->createDoiSubmission($event, $form, $contact);
        $this->doiSubmissionManager->save($doiSubmission);
        $tokenData    = new DoiTokenData($form->getId(), $doiSubmission->getHash());
        $encodedToken = $this->doiTokenParser->encode($tokenData);
        $doiLink      = $this->emailModel->buildUrl('mautic_doi_email_verify_action', [
            'token' => $encodedToken,
        ]);
        $tokens               = $event->getTokens();
        $tokens['{doi_link}'] = $doiLink;
        $event->setTokens($tokens);

        $verificationEmail = $doiConfig->getVerificationEmail();

        if (null === $verificationEmail || !$verificationEmail->isPublished()) {
            return false;
        }

        $contactFields = $contact->getProfileFields();
        $result        = $this->emailModel->sendEmail($verificationEmail, $contactFields, [
            'source'        => ['form', $form->getId()],
            'tokens'        => $event->getTokens(),
            'return_errors' => true,
            'ignoreDNC'     => true,
            'email_type'    => MailHelper::EMAIL_TYPE_TRANSACTIONAL,
        ]);

        if (true !== $result) {
            $this->logger->error('DOI verification email send failed', [
                'submission_id' => $doiSubmission->getId(),
                'email'         => $doiSubmission->getEmail(),
                'errors'        => $result,
            ]);

            return false;
        }

        return true;
    }

    private function createDoiSubmission(SubmissionEvent $event, Form $form, Lead $contact): FormDoiSubmission
    {
        $formSubmission  = $event->getSubmission();
        $hash            = $this->doiHashGenerator->generate($formSubmission->getId(), $contact->getEmail());
        $submittedValues = array_replace($formSubmission->getResults(), $event->getPost());

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission
            ->setFormSubmission($formSubmission)
            ->setForm($form)
            ->setSubmittedConsentSnapshot($this->consentSnapshotBuilder->build($form, $submittedValues, $event->getRequest()))
            ->setLead($contact)
            ->setEmail($contact->getEmail())
            ->setHash($hash)
            ->setDateCreated(new \DateTime());

        return $doiSubmission;
    }
}
