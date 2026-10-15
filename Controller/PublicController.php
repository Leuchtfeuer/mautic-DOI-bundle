<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Controller;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\FormRepository;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfigRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmissionRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiSubmissionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\ContactLastDoiDateUpdater;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiActionsDispatcher;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiTokenParser;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiVerificationHistoryRecorder;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\RuleEvaluator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class PublicController extends AbstractController
{
    public function __construct(
        private readonly FormRepository $formRepository,
        private readonly FormDoiSubmissionRepository $submissionRepository,
        private readonly FormDoiConfigRepository $configRepository,
        private readonly FormDoiSubmissionManager $submissionManager,
        private readonly DoiTokenParser $doiTokenParser,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly DoiActionsDispatcher $doiActionsDispatcher,
        private readonly ContactTracker $contactTracker,
        private readonly Config $config,
        private readonly DoiVerificationHistoryRecorder $verificationHistoryRecorder,
        private readonly ContactLastDoiDateUpdater $contactLastDoiDateUpdater,
    ) {
    }

    public function verifyEmailAction(string $token, Request $request): Response
    {
        $tokenData = $this->doiTokenParser->decode($token);

        if (null === $tokenData) {
            $this->logger->error('Failed to decode DOI token', ['token' => $token]);

            return $this->createErrorResponse();
        }

        $formId = $tokenData->formId;
        $hash   = $tokenData->hash;

        $form       = $this->formRepository->find($formId);
        $submission = $this->submissionRepository->findOneBy(['hash' => $hash]);

        if (!$form instanceof Form) {
            $this->logger->error('Form not found for DOI verification', ['formId' => $formId]);

            return $this->createErrorResponse();
        }

        if (!$submission instanceof FormDoiSubmission) {
            $this->logger->error('DOI submission not found', ['hash' => $hash, 'formId' => $formId]);

            return $this->createErrorResponse($form);
        }

        $this->contactTracker->setTrackedContact($submission->getLead());

        if ($submission->isConfirmed()) {
            return $this->createSuccessResponse($submission);
        }

        if ($submission->isTimedOut()) {
            $this->logger->info('DOI submission already timed out', ['hash' => $hash, 'formId' => $formId]);
            $this->verificationHistoryRecorder->recordFailure($submission);

            return $this->createErrorResponse($form);
        }

        if ($submission->isPending() && $this->isSubmissionExpired($submission)) {
            $this->logger->info('DOI submission has expired', ['hash' => $hash, 'formId' => $formId]);
            $submission->timeout();
            $this->submissionManager->save($submission);
            $this->verificationHistoryRecorder->recordFailure($submission);

            return $this->createErrorResponse($form);
        }

        if (!$submission->isPending()) {
            $this->logger->error('DOI submission is not pending', ['hash' => $hash, 'formId' => $formId, 'status' => $submission->getStatus()]);
            $this->verificationHistoryRecorder->recordFailure($submission);

            return $this->createErrorResponse($form);
        }

        $submission->confirm();

        // Generate and store browser proof token
        $browserProofToken = bin2hex(random_bytes(32));
        $submission->setBrowserProofToken($browserProofToken);

        $this->submissionManager->save($submission);
        $this->verificationHistoryRecorder->recordSuccess($submission);
        $this->contactLastDoiDateUpdater->updateFromSubmission($submission);
        $this->doiActionsDispatcher->executePostEmailVerificationActions($submission);

        return $this->createSuccessResponseWithCookie($submission, $browserProofToken, $request);
    }

    private function createSuccessResponse(FormDoiSubmission $submission): Response
    {
        $config = $this->configRepository->findOneBy(['form' => $submission->getForm()]);

        if ($config && $config->getSuccessRedirectUrl()) {
            return new RedirectResponse($config->getSuccessRedirectUrl());
        }

        return new Response($this->translator->trans('mautic.plugin.doi.verification.success'), Response::HTTP_OK);
    }

    private function createSuccessResponseWithCookie(FormDoiSubmission $submission, string $browserProofToken, Request $request): Response
    {
        $config = $this->configRepository->findOneBy(['form' => $submission->getForm()]);
        $cookie = Cookie::create(RuleEvaluator::COOKIE_NAME)
            ->withValue($browserProofToken)
            ->withExpires(new \DateTime('+365 days'))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly()
            ->withSameSite($this->cookieSameSite());

        if ($config && $config->getSuccessRedirectUrl()) {
            $response = new RedirectResponse($config->getSuccessRedirectUrl());
            $response->headers->setCookie($cookie);

            return $response;
        }

        $response = new Response($this->translator->trans('mautic.plugin.doi.verification.success'), Response::HTTP_OK);
        $response->headers->setCookie($cookie);

        return $response;
    }

    private function createErrorResponse(?Form $form = null): Response
    {
        if ($form) {
            $config = $this->configRepository->findOneBy(['form' => $form]);
            if ($config && $config->getErrorRedirectUrl()) {
                return new RedirectResponse($config->getErrorRedirectUrl());
            }
        }

        return new Response($this->translator->trans('mautic.plugin.doi.verification.error'), Response::HTTP_BAD_REQUEST);
    }

    private function isSubmissionExpired(FormDoiSubmission $submission): bool
    {
        $timeoutHours   = $this->config->getDoiLinkTimeout();
        $expirationTime = (clone $submission->getDateCreated())->modify("+{$timeoutHours} hours");

        return new \DateTime() > $expirationTime;
    }

    /**
     * Indirection keeps Rector's ReplaceArgumentDefaultValueRector from rewriting
     * withSameSite() to a FQCN constant that php-cs-fixer would shorten again.
     */
    private function cookieSameSite(): string
    {
        return Cookie::SAMESITE_LAX;
    }
}
