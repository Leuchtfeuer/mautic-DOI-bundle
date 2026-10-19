<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\FormBundle\Entity\SubmissionRepository;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionExecutionLog;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionExecutionLogRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Mapper\FormDoiActionMapper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoiActionsDispatcher
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FormDoiActionRepository $formDoiActionRepository,
        private readonly FormDoiActionMapper $formDoiActionMapper,
        private readonly SubmissionRepository $submissionRepository,
        private readonly FormModel $formModel,
        private readonly SubmissionEventRecreator $submissionEventRecreator,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly FormDoiActionExecutionLogRepository $formDoiActionExecutionLogRepository,
    ) {
    }

    public function executePostEmailVerificationActions(FormDoiSubmission $doiSubmission): void
    {
        $form             = $doiSubmission->getForm();
        $formSubmissionId = $doiSubmission->getFormSubmission()->getId();
        $formSubmission   = $this->submissionRepository->getEntity($formSubmissionId); // loads entity with results
        $doiActions       = $this->formDoiActionRepository->findBy(['form' => $form]);
        $customComponents = $this->formModel->getCustomComponents();
        $availableActions = $customComponents['actions'] ?? [];

        // Recreate the submission context as much as possible
        $results             = $formSubmission->getResults() ?: [];
        $post                = $results; // Use the stored results as POST data
        $contactFieldMatches = $this->submissionEventRecreator->getContactFieldMatches($form, $results);
        // Recreate server array with available information
        $server  = $this->submissionEventRecreator->getServerData($formSubmission);
        $request = $this->submissionEventRecreator->getRequest($post, $server);

        foreach ($doiActions as $doiAction) {
            $shouldExecute = $this->ruleEvaluator->shouldExecuteAction(
                $doiAction,
                $formSubmission,
                $formSubmission->getLead()
            );

            if (!$shouldExecute) {
                $this->formDoiActionExecutionLogRepository->logExecution(
                    $formSubmission,
                    $doiSubmission,
                    $doiAction,
                    false,
                    FormDoiActionExecutionLog::DETAILS_CONDITIONS_NOT_MET
                );
                continue;
            }

            $submissionEvent = new SubmissionEvent($formSubmission, $post, $server, $request);
            $submissionEvent->setResults($results);
            $submissionEvent->setContactFieldMatches($contactFieldMatches);
            $submissionEvent->setContext($doiAction->getType());
            $submissionEvent->setAction($this->formDoiActionMapper->mapToAction($doiAction));
            $actionEvent = $availableActions[$doiAction->getType()]['eventName'];

            try {
                $this->eventDispatcher->dispatch($submissionEvent, $actionEvent);

                $this->formDoiActionExecutionLogRepository->logExecution(
                    $formSubmission,
                    $doiSubmission,
                    $doiAction,
                    true,
                    FormDoiActionExecutionLog::DETAILS_CONDITIONS_MET
                );
            } catch (\Throwable $e) {
                $this->formDoiActionExecutionLogRepository->logExecution(
                    $formSubmission,
                    $doiSubmission,
                    $doiAction,
                    true,
                    FormDoiActionExecutionLog::DETAILS_ERROR
                );
                throw $e;
            }
        }
    }
}
