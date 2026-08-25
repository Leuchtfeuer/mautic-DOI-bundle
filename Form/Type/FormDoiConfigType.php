<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\EmailBundle\Form\Type\EmailListType;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Form\DataTransformer\FieldFilterTransformer;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\AvailableSkipOptions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

class FormDoiConfigType extends AbstractType
{
    public function __construct(
        private TranslatorInterface $translator,
        private AvailableSkipOptions $availableSkipOptions,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', YesNoButtonGroupType::class, [
                'label' => 'mautic.plugin.doi.form.field.enabled',
                'attr'  => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.enabled.tooltip',
                ],
            ])
            ->add('skipOnCookie', YesNoButtonGroupType::class, [
                'label' => 'mautic.plugin.doi.form.field.skip_on_cookie',
                'attr'  => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.skip_on_cookie.tooltip',
                ],
            ])
            ->add('verificationEmailId', EmailListType::class, [
                'label'      => 'mautic.plugin.doi.form.field.verification_email',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'multiple'   => false,
                'model'      => 'email',
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.verification_email.tooltip',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'mautic.plugin.doi.form.field.verification_email.required',
                        'groups'  => ['doi_enabled'],
                    ]),
                ],
            ])
            ->add('followUpEmailId', EmailListType::class, [
                'label'      => 'mautic.plugin.doi.form.field.followup_email',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'multiple'   => false,
                'model'      => 'email',
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.followup_email.tooltip',
                ],
            ])
            ->add('successRedirectUrl', UrlType::class, [
                'label'      => 'mautic.plugin.doi.form.field.success_redirect_url',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.success_redirect_url.tooltip',
                ],
                'constraints' => [
                    new Url([
                        'message' => 'mautic.core.valid_url_required',
                        'groups'  => ['doi_config'],
                    ]),
                ],
            ])
            ->add('errorRedirectUrl', UrlType::class, [
                'label'      => 'mautic.plugin.doi.form.field.error_redirect_url',
                'label_attr' => ['class' => 'control-label'],
                'required'   => false,
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.plugin.doi.form.field.error_redirect_url.tooltip',
                ],
                'constraints' => [
                    new Url([
                        'message' => 'mautic.core.valid_url_required',
                        'groups'  => ['doi_config'],
                    ]),
                ],
            ]);

        $skipPostActionChoices = [
            'mautic.form.form.postaction.return'   => 'return',
            'mautic.form.form.postaction.message'  => 'message',
            'mautic.form.form.postaction.redirect' => 'redirect',
        ];

        // add support for external post-action when it's available
        $translationKey = 'mautic.form.form.postaction.hideform';
        $translated     = $this->translator->trans($translationKey);
        if ($translated !== $translationKey) {
            $skipPostActionChoices[$translationKey] = 'hideform';
        }

        $builder
            ->add('skipPostAction', ChoiceType::class, [
                'choices'           => $skipPostActionChoices,
                'label'             => 'mautic.plugin.doi.form.field.skip_post_action',
                'label_attr'        => ['class' => 'control-label'],
                'attr'              => [
                    'class'    => 'form-control',
                    'tooltip'  => 'mautic.plugin.doi.form.field.skip_post_action.tooltip',
                ],
                'required'    => false,
                'placeholder' => false,
            ])
            ->add('skipPostActionProperty', TextType::class, [
                'label'      => 'mautic.plugin.doi.form.field.skip_post_action_property',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'         => 'form-control',
                    'tooltip'       => 'mautic.plugin.doi.form.field.skip_post_action_property.tooltip',
                ],
                'required'    => false,
                'constraints' => [
                    new NotBlank([
                        'message' => 'mautic.form.form.postactionproperty_redirect.notblank',
                        'groups'  => ['skip_redirect'],
                    ]),
                ],
            ]);

        $builder->add('deleteAfterTimeout', YesNoButtonGroupType::class, [
            'label' => 'mautic.plugin.doi.form.field.delete_after_timeout',
            'attr'  => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.plugin.doi.form.field.delete_after_timeout.tooltip',
            ],
        ]);

        if (isset($options['mautic_form'])) {
            $builder->setAttribute('mautic_form', $options['mautic_form']);
        }

        $filterModalTransformer = new FieldFilterTransformer($this->translator, ['object' => 'lead']);
        $builder->add(
            $builder->create(
                'skipConditions',
                CollectionType::class,
                [
                    'entry_type'     => SkipConditionType::class,
                    'entry_options'  => [
                        'mautic_form' => $options['mautic_form'] ?? null,
                    ],
                    'error_bubbling' => false,
                    'mapped'         => true,
                    'allow_add'      => true,
                    'allow_delete'   => true,
                    'label'          => false,
                    'block_prefix'   => '_doiconfig_skipconditions',
                ]
            )->addModelTransformer($filterModalTransformer)
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $formEntity           = $form->getConfig()->getAttribute('mautic_form');
        $view->vars['fields'] = $this->availableSkipOptions->getAvailableSkipOptions($formEntity);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'validation_groups' => function (FormInterface $form): array {
                $data   = $form->getData();
                $groups = ['doi_config'];

                if (isset($data['enabled']) && $data['enabled']) {
                    $groups[] = 'doi_enabled';
                }

                if ('redirect' === ($data['skipPostAction'] ?? null)) {
                    $groups[] = 'skip_redirect';
                }

                return $groups;
            },
            'data_class'  => null, // Allow array data
            'mautic_form' => null,
        ]);

        $resolver->setAllowedTypes('mautic_form', ['null', Form::class]);
    }
}
