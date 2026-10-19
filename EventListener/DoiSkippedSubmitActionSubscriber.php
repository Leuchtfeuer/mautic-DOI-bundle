<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Mautic\FormBundle\Event\SubmissionEvent;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiSkippedSubmitActionHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DoiSkippedSubmitActionSubscriber implements EventSubscriberInterface
{
    public const DOI_SKIP_ACTION_POST_SUBMIT_CALLBACK = 'doi.skip_action';

    public function __construct(
        private readonly DoiSkippedSubmitActionHandler $skipActionHandler,
    ) {
    }

    public function onSkipPostAction(SubmissionEvent $event): void
    {
        $callbackConfig = $event->getPostSubmitCallback(self::DOI_SKIP_ACTION_POST_SUBMIT_CALLBACK);
        if (!is_array($callbackConfig) || [] === $callbackConfig) {
            return;
        }

        $this->skipActionHandler->processSkipAction($event, $callbackConfig['doiConfig']);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DoiEvents::DOI_ON_SKIP_POST_ACTION => ['onSkipPostAction', 0],
        ];
    }
}
