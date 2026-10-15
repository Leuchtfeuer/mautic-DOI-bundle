<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Form\Type;

use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\GreaterThan;

/**
 * @extends AbstractType<mixed>
 */
final class FeatureSettingsType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'followup_wait_time',
            IntegerType::class,
            [
                'label'       => 'mautic.plugin.doi.config.followup_wait_time',
                'attr'        => [
                    'tooltip' => 'mautic.plugin.doi.config.followup_wait_time_tooltip',
                    'class'   => 'form-control',
                ],
                'constraints' => [
                    new GreaterThan(value: 0, message: 'mautic.plugin.doi.config.followup_wait_time.positive'),
                ],
            ]
        );

        $builder->add(
            'doi_link_timeout',
            IntegerType::class,
            [
                'label'       => 'mautic.plugin.doi.config.doi_link_timeout',
                'attr'        => [
                    'tooltip' => 'mautic.plugin.doi.config.doi_link_timeout_tooltip',
                    'class'   => 'form-control',
                ],
                'constraints' => [
                    new GreaterThan(value: 0, message: 'mautic.plugin.doi.config.doi_link_timeout.positive'),
                ],
            ]
        );

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (null === $data || (is_array($data) && !isset($data['followup_wait_time']))) {
                $data                       = $data ?: [];
                $data['followup_wait_time'] = Config::DEFAULT_FOLLOWUP_WAIT_TIME;
            }
            if (is_array($data) && !isset($data['doi_link_timeout'])) {
                $data['doi_link_timeout'] = Config::DEFAULT_DOI_LINK_TIMEOUT;
            }
            $event->setData($data);
        });
    }
}
