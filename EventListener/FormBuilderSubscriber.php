<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Event\FormEvent;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiActionConditionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiActionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class FormBuilderSubscriber implements EventSubscriberInterface
{
    private const SESSION_ID_KEY                   = 'sessionId';

    private const DOI_CONFIG_KEY                   = 'doiConfig';

    private const DOI_ACTION_CONDITIONS_CONFIG_KEY = 'doiActionConditionsConfig';

    public function __construct(
        private readonly FormDoiActionManager $formDoiActionManager,
        private readonly FormDoiActionSessionManager $formDoiActionSessionManager,
        private readonly DoiConfigManager $doiConfigManager,
        private readonly DoiActionConditionManager $doiActionConditionManager,
        private readonly RequestStack $requestStack,
        private readonly Config $pluginConfig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_POST_SAVE => ['onFormPostSave', 0],
        ];
    }

    public function onFormPostSave(FormEvent $event): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $form       = $event->getForm();
        $request    = $this->requestStack->getCurrentRequest();
        $mauticForm = $request->request->all()['mauticform'] ?? null;

        if (!isset($mauticForm) || !is_array($mauticForm)) {
            return;
        }

        $sessionId                  = $mauticForm[self::SESSION_ID_KEY] ?? '';
        $doiConfig                  = $mauticForm[self::DOI_CONFIG_KEY] ?? [];
        $doiActionsConditionsConfig = $mauticForm[self::DOI_ACTION_CONDITIONS_CONFIG_KEY] ?? [];

        $this->handleSaveDoiActions($form, $sessionId, $doiActionsConditionsConfig);
        $this->handleSaveDoiConfig($form, $doiConfig);
    }

    /**
     * @param array<string, mixed> $doiActionsConditionsConfig
     */
    private function handleSaveDoiActions(Form $form, string $sessionId, array $doiActionsConditionsConfig): void
    {
        $actions                = $this->formDoiActionSessionManager->getActionsFromSession($sessionId);
        $actionsMap             = $this->formDoiActionManager->saveActions($form, $actions);
        $actionConditionsData   = $doiActionsConditionsConfig['actionConditions'] ?? [];
        $this->doiActionConditionManager->saveActionConditions($form, $actionsMap, $actionConditionsData);
    }

    /**
     * @param array<string, mixed> $doiConfigData
     */
    private function handleSaveDoiConfig(Form $form, array $doiConfigData = []): void
    {
        $this->doiConfigManager->saveFormDoiConfig($form, $doiConfigData);
    }
}
