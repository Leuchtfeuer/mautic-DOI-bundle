<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Type;

use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Form\Type\FilterPropertiesType;
use Mautic\LeadBundle\Provider\FormAdjustmentsProviderInterface;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<mixed>
 */
class DoiConditionType extends AbstractType
{
    public function __construct(
        private readonly FormAdjustmentsProviderInterface $formAdjustmentsProvider,
        private readonly AvailableSkipOptions $availableOptionsProvider,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (isset($options['mautic_form'])) {
            $builder->setAttribute('mautic_form', $options['mautic_form']);
        }

        $formEntity   = $options['mautic_form'] ?? null;
        $fieldChoices = $this->availableOptionsProvider->getAvailableOptions($formEntity);

        $builder->add(
            'glue',
            ChoiceType::class,
            [
                'label'   => false,
                'choices' => [
                    'mautic.lead.list.form.glue.and' => 'and',
                    'mautic.lead.list.form.glue.or'  => 'or',
                ],
                'attr' => [
                    'class'    => 'form-control input-sm not-chosen glue-select',
                    'onchange' => 'Mautic.doiUpdateConditionPositioning(this)',
                ],
            ]
        );

        $formModifier = function (FormEvent $event) use ($fieldChoices): void {
            $data        = (array) $event->getData();
            $form        = $event->getForm();
            $fieldAlias  = $data['field'] ?? '';
            $fieldObject = $data['object'] ?? 'lead';
            $field       = $fieldChoices[$fieldObject][$fieldAlias] ?? null;
            $operators   = $field['operators'] ?? [];
            $operator    = $data['operator'] ?? null;

            if ($operators && !$operator) {
                $operator = array_key_first($operators);
            }

            // If field not found (e.g., clone scenario), disable validation
            $disableValidation = null === $field && $fieldAlias;

            $form->add(
                'operator',
                ChoiceType::class,
                [
                    'label'   => false,
                    'choices' => $disableValidation ? [$operator => $operator] : $operators,
                    'attr'    => [
                        'class'    => 'form-control not-chosen',
                        'onchange' => 'Mautic.doiConvertConditionInput(this)',
                    ],
                ]
            );

            $form->add(
                'properties',
                FilterPropertiesType::class,
                [
                    'label'              => false,
                    'allow_extra_fields' => true,
                ]
            );

            if (null === $field) {
                return;
            }

            $filterPropertiesType = $form->get('properties');
            $filterPropertiesType->setData($data['properties'] ?? []);

            if ($fieldAlias && $operator) {
                $this->formAdjustmentsProvider->adjustForm(
                    $filterPropertiesType,
                    $fieldAlias,
                    $fieldObject,
                    $operator,
                    $field
                );

                if ($filterPropertiesType->has('alert')) {
                    $filterPropertiesType->remove('alert');
                }
            }
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $formModifier);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $formModifier);
        $builder->add('field', HiddenType::class);
        $builder->add('object', HiddenType::class);
        $builder->add('type', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label'          => false,
            'error_bubbling' => false,
            'mautic_form'    => null,
        ]);

        $resolver->setAllowedTypes('mautic_form', ['null', Form::class]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $formEntity           = $form->getConfig()->getAttribute('mautic_form');
        $view->vars['fields'] = $this->availableOptionsProvider->getAvailableOptions($formEntity);
    }

    public function getBlockPrefix(): string
    {
        return 'conditional_doi_action_condition';
    }
}
