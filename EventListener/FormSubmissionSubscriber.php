<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiSubmissionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiActionsDispatcher;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiHashGenerator;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiVerificationHistoryRecorder;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\RuleEvaluator;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\VerificationEmailSender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormSubmissionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private VerificationEmailSender $verificationEmailSender,
        private Config $pluginConfig,
        private RuleEvaluator $ruleEvaluator,
        private DoiConfigManager $doiConfigManager,
        private FormDoiSubmissionManager $submissionManager,
        private DoiActionsDispatcher $actionsDispatcher,
        private DoiHashGenerator $hashGenerator,
        private DoiVerificationHistoryRecorder $verificationHistoryRecorder,
    ) {
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $form      = $event->getSubmission()->getForm();
        $doiConfig = $this->doiConfigManager->getFormDoiConfig($form);

        // If DOI is not enabled for this form, skip processing
        if (null === $doiConfig || !$doiConfig->isEnabled()) {
            return;
        }

        $contact = $event->getLead();
        if (null === $contact || !$contact->getEmail()) {
            return;
        }

        // Check if we should skip verification based on a cookie
        if ($this->ruleEvaluator->shouldSkipBasedOnCookie($doiConfig, $contact->getEmail())) {
            $this->handleSkippedVerification($event, FormDoiSubmission::SKIP_REASON_COOKIE_MATCH);

            return;
        }

        if ($this->ruleEvaluator->shouldSkipBasedOnConditions($doiConfig, $event->getSubmission(), $contact)) {
            $this->handleSkippedVerification($event, FormDoiSubmission::SKIP_REASON_CONDITION_MATCH);

            return;
        }

        // Default behavior: send verification email
        $this->verificationEmailSender->send($event);
    }

    private function handleSkippedVerification(SubmissionEvent $event, string $skipReason): void
    {
        $formSubmission = $event->getSubmission();
        $form           = $formSubmission->getForm();
        $contact        = $event->getLead();

        if (null === $contact || !$contact->getEmail()) {
            return;
        }

        // Get DOI configuration
        $doiConfig = $this->doiConfigManager->getFormDoiConfig($form);
        if (null === $doiConfig) {
            return;
        }

        // Create a DOI submission record marked as skipped
        $hash = $this->hashGenerator->generate($formSubmission->getId(), $contact->getEmail());

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission
            ->setFormSubmission($formSubmission)
            ->setForm($form)
            ->setLead($contact)
            ->setEmail($contact->getEmail())
            ->setHash($hash)
            ->setDateCreated(new \DateTime())
            ->skip($skipReason);

        $this->submissionManager->save($doiSubmission);
        $this->verificationHistoryRecorder->recordSkipped($doiSubmission);

        // Register callback for custom skip action (executed BEFORE standard actions)
        if ($doiConfig->getSkipPostAction()) {
            $event->setPostSubmitCallback(
                DoiSkippedSubmitActionSubscriber::DOI_SKIP_ACTION_POST_SUBMIT_CALLBACK,
                [
                    'eventName' => DoiEvents::DOI_ON_SKIP_POST_ACTION,
                    'doiConfig' => $doiConfig,
                    'priority'  => 100, // Higher priority to execute first
                ]
            );
        }

        // Execute post-verification actions immediately since we're skipping verification
        $this->actionsDispatcher->executePostEmailVerificationActions($doiSubmission);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', 0],
        ];
    }
}
