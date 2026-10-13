<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Extension;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Form\Type\FormType;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Form\Type\FormDoiActionConditionsConfigType;
use MauticPlugin\LeuchtfeuerDoiBundle\Form\Type\FormDoiConfigType;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiActionConditionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\FormDoiActionManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class FormTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly DoiConfigManager $doiConfigManager,
        private readonly Config $pluginConfig,
        private readonly RequestStack $requestStack,
        private readonly DoiActionConditionManager $doiActionConditionManager,
        private readonly FormDoiActionManager $formDoiActionManager,
        private readonly FormDoiActionSessionManager $formDoiActionSessionManager,
        private readonly FormModel $formModel,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->onPreSetData(...));
    }

    public function onPreSetData(FormEvent $event): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $form   = $event->getForm();
        $entity = $event->getData();

        $this->addDoiConfig($form, $entity);
        if ($this->isCloneRequest()) {
            $clonedActions = $this->handleFormClone($form);
        }
        $this->addDoiActionConditions($form, $entity, $clonedActions ?? []);
    }

    private function addDoiConfig(FormInterface $form, Form $entity): void
    {
        if ($entity->getId()) {
            // This is an existing form, load its config
            $doiConfig = $this->doiConfigManager->getFormDoiConfig($entity) ?? new FormDoiConfig();
        } else {
            // This is a new or cloned form.
            $doiConfig = null;

            if ($this->isCloneRequest()) {
                $doiConfig = $this->getClonedDoiConfigFromRequest();
            }

            // If it's not a clone or cloning failed, create a fresh config
            if (null === $doiConfig) {
                $doiConfig = new FormDoiConfig();
            }
        }

        // Convert entities to IDs for the form
        $formData = [
            'verificationEmailId'      => $doiConfig->getVerificationEmail()?->getId(),
            'followUpEmailId'          => $doiConfig->getFollowUpEmail()?->getId(),
            'successRedirectUrl'       => $doiConfig->getSuccessRedirectUrl(),
            'errorRedirectUrl'         => $doiConfig->getErrorRedirectUrl(),
            'enabled'                  => $doiConfig->isEnabled(),
            'skipOnCookie'             => $doiConfig->isSkipOnCookie(),
            'skipPostAction'           => $doiConfig->getSkipPostAction(),
            'skipPostActionProperty'   => $doiConfig->getSkipPostActionProperty(),
            'skipConditions'           => $doiConfig->getSkipConditions(),
            'deleteAfterTimeout'       => $doiConfig->isDeleteAfterTimeout(),
        ];

        // Add DOI config fields with ID data
        $form->add('doiConfig', FormDoiConfigType::class, [
            'data'        => $formData,
            'mapped'      => false,
            'mautic_form' => $entity,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $clonedActions
     */
    private function addDoiActionConditions(FormInterface $symfonyForm, Form $mauticForm, array $clonedActions): void
    {
        $actionConditionsData = [];

        if ($mauticForm->getId()) {
            // Existing form - load conditions for all actions
            $actions          = $this->formDoiActionManager->getFormDoiActionEntities($mauticForm);
            $actionConditions = $this->doiActionConditionManager->getFormActionConditions($mauticForm);

            foreach ($actions as $action) {
                $actionId  = $action->getId();
                $condition = $actionConditions[$actionId] ?? null;

                $actionConditionsData[$actionId] = [
                    'actionId'   => $actionId,
                    'conditions' => $condition?->getConditions() ?? [],
                ];
            }
        } elseif ($this->isCloneRequest()) {
            $mainRequest  = $this->requestStack->getMainRequest();
            $sourceFormId = (int) $mainRequest?->attributes->get('objectId');

            if ($sourceFormId) {
                $actionConditions = $this->doiActionConditionManager->getFormActionConditionsByFormId($sourceFormId);

                foreach ($clonedActions as $index => $action) {
                    $condition                           = $actionConditions[$index] ?? null;
                    $actionConditionsData[$action['id']] = [
                        'actionId'   => $action['id'],
                        'conditions' => $condition?->getConditions() ?? [],
                    ];
                }
            }
        }

        $symfonyForm->add('doiActionConditionsConfig', FormDoiActionConditionsConfigType::class, [
            'data' => [
                'actionConditions' => $actionConditionsData,
            ],
            'mapped'      => false,
            'mautic_form' => $mauticForm,
            'label'       => false,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function handleFormClone(FormInterface $symfonyForm): array
    {
        $mainRequest  = $this->requestStack->getMainRequest();
        $sourceFormId = (int) $mainRequest?->attributes->get('objectId');

        if (!$sourceFormId) {
            return [];
        }

        $actionUrl = $symfonyForm->getConfig()->getAction();
        $path      = parse_url($actionUrl, PHP_URL_PATH);
        $sessionId = basename((string) $path);

        if (!$sessionId || !str_starts_with($sessionId, 'mautic_')) {
            return [];
        }

        $sourceForm = $this->formModel->getEntity($sourceFormId);
        if (!$sourceForm) {
            return [];
        }

        $actionMap  = [];
        $doiActions = $this->formDoiActionManager->getFormDoiActions($sourceForm);
        foreach ($doiActions as &$action) {
            $sourceId             = $action['id'];
            $action['id']         = 'new'.hash('sha1', uniqid((string) mt_rand()));
            $action['form']       = null;
            $actionMap[$sourceId] = $action;
        }

        $this->formDoiActionSessionManager->loadActionsIntoSession($sessionId, $doiActions);

        return $actionMap;
    }

    private function isCloneRequest(): bool
    {
        $mainRequest = $this->requestStack->getMainRequest();

        return $mainRequest && 'clone' === $mainRequest->attributes->get('objectAction');
    }

    private function getClonedDoiConfigFromRequest(): ?FormDoiConfig
    {
        $mainRequest  = $this->requestStack->getMainRequest();
        $sourceFormId = (int) $mainRequest?->attributes->get('objectId');

        if ($sourceFormId) {
            $originalDoiConfig = $this->doiConfigManager->getFormDoiConfigByFormId($sourceFormId);
            if ($originalDoiConfig) {
                return clone $originalDoiConfig;
            }
        }

        return null;
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
