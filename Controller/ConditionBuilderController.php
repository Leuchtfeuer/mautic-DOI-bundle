<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Controller;

use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Form\Type\FilterPropertiesType;
use Mautic\LeadBundle\Provider\FormAdjustmentsProviderInterface;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ConditionBuilderController extends AbstractController
{
    private const TMP_NAME = 'RENAME';

    public function __construct(private readonly FormFactoryInterface $formFactory, private readonly FormAdjustmentsProviderInterface $formAdjustmentsProvider, private readonly AvailableSkipOptions $availableSkipOptions, private readonly FormModel $formModel)
    {
    }

    public function renderSkipConditionAction(Request $request): JsonResponse
    {
        $filterNum = (int) $request->query->get('filterNum');

        // Define the specific HTML ID/Name patterns for this action
        $newId   = "id=\"mauticform_doiConfig_skipConditions_{$filterNum}_properties";
        $newName = "name=\"mauticform[doiConfig][skipConditions][{$filterNum}][properties]";

        return $this->processConditionForm($request, $newId, $newName);
    }

    public function renderDoiActionConditionAction(Request $request): JsonResponse
    {
        $actionId     = InputHelper::clean((string) $request->query->get('actionId'));
        $conditionNum = (int) $request->query->get('conditionNum');

        // Define the specific HTML ID/Name patterns for this action
        $newId   = "id=\"mauticform_doiActionConditionsConfig_actionConditions_{$actionId}_conditions_{$conditionNum}_properties";
        $newName = "name=\"mauticform[doiActionConditionsConfig][actionConditions][{$actionId}][conditions][{$conditionNum}][properties]";

        return $this->processConditionForm($request, $newId, $newName);
    }

    /**
     * Central logic to handle form adjusting and rendering.
     */
    private function processConditionForm(
        Request $request,
        string $targetIdString,
        string $targetNameString,
    ): JsonResponse {
        $fieldAlias  = InputHelper::clean((string) $request->query->get('fieldAlias'));
        $fieldObject = InputHelper::clean((string) $request->query->get('fieldObject'));
        $operator    = InputHelper::clean((string) $request->query->get('operator'));
        $search      = InputHelper::clean((string) $request->query->get('search'));
        $formId      = InputHelper::clean((string) $request->query->get('formId'));

        $formEntity = $this->formModel->getEntity($formId);
        $form       = $this->formFactory->createNamed(self::TMP_NAME, FilterPropertiesType::class);

        if ($fieldAlias && $operator) {
            $options = $this->availableSkipOptions->getAvailableSkipOptions($formEntity, $search);

            // Validate options exist to prevent error
            $specificOptions = $options[$fieldObject][$fieldAlias] ?? [];

            $this->formAdjustmentsProvider->adjustForm(
                $form,
                $fieldAlias,
                $fieldObject,
                $operator,
                $specificOptions
            );

            if ($form->has('alert')) {
                $form->remove('alert');
            }
        }

        $formHtml = $this->renderView(
            '@MauticLead/List/filterpropform.html.twig',
            [
                'form' => $form->createView(),
            ]
        );

        $formHtml = str_replace('id="'.self::TMP_NAME, $targetIdString, $formHtml);
        $formHtml = str_replace('name="'.self::TMP_NAME, $targetNameString, $formHtml);

        return new JsonResponse([
            'viewParameters' => [
                'form' => $formHtml,
            ],
        ]);
    }
}
