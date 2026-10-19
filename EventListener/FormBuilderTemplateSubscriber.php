<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomTemplateEvent;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiActionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormBuilderTemplateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FormDoiActionManager $formDoiActionManager,
        private readonly FormDoiActionSessionManager $formDoiActionSessionManager,
        private readonly Config $pluginConfig,
        private readonly AvailableSkipOptions $availableSkipOptions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_TEMPLATE => ['onTemplateRender', 0],
        ];
    }

    public function onTemplateRender(CustomTemplateEvent $event): void
    {
        if ('@MauticForm/Builder/index.html.twig' === $event->getTemplate() && $this->pluginConfig->isPublished()) {
            $vars = $event->getVars();
            /** @var Form $form */
            $form = $vars['activeForm'];

            if ($form->getId()) {
                $formDoiActions = $this->formDoiActionManager->getFormDoiActions($form);
                $this->formDoiActionSessionManager->loadActionsIntoSession($form->getId(), $formDoiActions);
            } else {
                $sessionId              = $this->getFormSessionId($event);
                $formDoiActions         = $this->formDoiActionSessionManager->getActionsFromSession($sessionId);
            }
            $vars['formDoiActions']        = $formDoiActions;
            $vars['conditionChoiceFields'] = $this->availableSkipOptions->getAvailableSkipOptions($form);

            $event->setVars($vars);
            $event->setTemplate('@LeuchtfeuerDoi/Builder/index.html.twig');
        }
    }

    private function getFormSessionId(CustomTemplateEvent $event): ?string
    {
        try {
            $mauticForm = $event->getRequest()->request->all()['mauticform'] ?? null;
            $sessionId  = $mauticForm['sessionId'] ?? null;

            // If null, extract the sessionId from the form vars
            if (null === $sessionId) {
                $templateVars = $event->getVars();
                $form         = $templateVars['form'] ?? null;
                $sessionId    = $form->children['sessionId']->vars['value'] ?? null;
            }

            return $sessionId;
        } catch (\Throwable) {
            return null;
        }
    }
}
