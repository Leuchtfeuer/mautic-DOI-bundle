<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Type;

use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Form\DataTransformer\FieldFilterTransformer;
use Mautic\LeadBundle\Segment\RelativeDate;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<mixed>
 */
class DoiActionConditionEntryType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AvailableSkipOptions $availableOptionsProvider,
        private readonly RelativeDate $relativeDate,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (isset($options['mautic_form'])) {
            $builder->setAttribute('mautic_form', $options['mautic_form']);
        }

        // Hidden field to store action ID or temporary identifier
        $builder->add('actionId', HiddenType::class);

        // Collection of conditions for this action
        $filterModalTransformer = new FieldFilterTransformer($this->translator, $this->relativeDate, ['object' => 'lead']);

        $builder->add(
            $builder->create(
                'conditions',
                CollectionType::class,
                [
                    'entry_type'     => DoiConditionType::class,
                    'entry_options'  => [
                        'mautic_form' => $options['mautic_form'] ?? null,
                    ],
                    'error_bubbling' => false,
                    'allow_add'      => true,
                    'allow_delete'   => true,
                    'label'          => false,
                    'block_prefix'   => 'conditional_doi_action_conditions',
                ]
            )->addModelTransformer($filterModalTransformer)
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $formEntity           = $form->getConfig()->getAttribute('mautic_form');
        $view->vars['fields'] = $this->availableOptionsProvider->getAvailableOptions($formEntity);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label'       => false,
            'mautic_form' => null,
        ]);

        $resolver->setAllowedTypes('mautic_form', ['null', Form::class]);
    }
}
