<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Enum',
    ];

    $services->load('MauticPlugin\\LeuchtfeuerDoiBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    $services->load('MauticPlugin\\LeuchtfeuerDoiBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes, ['e2e'])).'}');

    $services->get(MauticPlugin\LeuchtfeuerDoiBundle\Integration\LeuchtfeuerDoiIntegration::class)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration');
    $services->get(MauticPlugin\LeuchtfeuerDoiBundle\Integration\Support\ConfigSupport::class)
        ->tag('mautic.config_integration');

    $services->alias('mautic.integration.leuchtfeuerdoi', MauticPlugin\LeuchtfeuerDoiBundle\Integration\LeuchtfeuerDoiIntegration::class);
};
