<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiAction;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionConditionRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionRepository;

class FormDoiActionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormDoiActionRepository $formDoiActionRepository,
        private readonly FormDoiActionConditionRepository $formDoiActionConditionRepository,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFormDoiActions(Form $form): array
    {
        $actions = $this->formDoiActionRepository->findBy(['form' => $form], ['order' => 'ASC']);

        return array_map(fn (FormDoiAction $action): array => $action->convertToArray(), $actions);
    }

    /**
     * @return array<int, FormDoiAction>
     */
    public function getFormDoiActionEntities(Form $form): array
    {
        return $this->formDoiActionRepository->findBy(['form' => $form], ['order' => 'ASC']);
    }

    /**
     * @param array<int, array<string, mixed>> $newActions
     *
     * @return array<string|int, FormDoiAction> Map of [id => action] for matching new action IDs
     */
    public function saveActions(Form $form, array $newActions): array
    {
        $existingActions   = $this->formDoiActionRepository->findBy(['form' => $form]);
        $existingActionMap = [];
        foreach ($existingActions as $action) {
            $existingActionMap[$action->getId()] = $action;
        }

        $resultMap = [];

        foreach ($newActions as $newAction) {
            if (isset($newAction['id']) && isset($existingActionMap[$newAction['id']])) {
                // Update existing action
                $action = $existingActionMap[$newAction['id']];
                unset($existingActionMap[$newAction['id']]);
            } else {
                // Create new action
                $action = new FormDoiAction();
                $action->setForm($form);
            }

            $action->setName($newAction['name']);
            $action->setDescription($newAction['description']);
            $action->setType($newAction['type']);
            $action->setProperties($newAction['properties']);
            $action->setOrder($newAction['order'] ?? 0);

            $this->entityManager->persist($action);

            if (isset($newAction['id'])) {
                $resultMap[$newAction['id']] = $action;
            }
        }

        // Remove actions that are no longer present
        foreach ($existingActionMap as $actionToRemove) {
            $condition = $this->formDoiActionConditionRepository->findOneBy(['action' => $actionToRemove]);
            if ($condition) {
                $this->entityManager->remove($condition);
            }
            $this->entityManager->remove($actionToRemove);
        }

        $this->entityManager->flush();

        return $resultMap;
    }
}
