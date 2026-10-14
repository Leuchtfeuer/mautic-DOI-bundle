<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Type;

use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<mixed>
 */
class FormDoiActionConditionsConfigType extends AbstractType
{
    public function __construct(
        private readonly AvailableSkipOptions $availableOptionsProvider,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Store the form entity if provided
        if (isset($options['mautic_form'])) {
            $builder->setAttribute('mautic_form', $options['mautic_form']);
        }

        // Collection of action conditions, indexed by action ID or temp ID
        $builder->add('actionConditions', CollectionType::class, [
            'entry_type'    => DoiActionConditionEntryType::class,
            'entry_options' => [
                'mautic_form' => $options['mautic_form'] ?? null,
            ],
            'allow_add'    => true,
            'allow_delete' => true,
            'by_reference' => false,
            'label'        => false,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $mauticForm                          = $form->getConfig()->getAttribute('mautic_form');
        $view->vars['conditionChoiceFields'] = $this->availableOptionsProvider->getAvailableOptions($mauticForm);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => null,
            'mautic_form' => null,
        ]);

        $resolver->setAllowedTypes('mautic_form', ['null', Form::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'doi_action_conditions_config';
    }
}
