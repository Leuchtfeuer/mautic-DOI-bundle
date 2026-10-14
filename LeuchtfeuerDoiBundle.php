<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;
use Mautic\IntegrationsBundle\Migration\Engine;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\TablePrefixResolver;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\LastDoiDateFieldInstaller;

class LeuchtfeuerDoiBundle extends AbstractPluginBundle
{
    /**
     * @param array<int, mixed>|null $metadata
     * @param bool|null              $installedSchema
     */
    public static function onPluginInstall(Plugin $plugin, MauticFactory $factory, $metadata = null, $installedSchema = null): void
    {
        self::onPluginUpdate($plugin, $factory, $metadata);
    }

    /**
     * @param array<int, mixed>|null $metadata
     */
    public static function onPluginUpdate(Plugin $plugin, MauticFactory $factory, $metadata = null, ?Schema $installedSchema = null): void
    {
        $entityManager = $factory->getEntityManager();
        $tablePrefix   = TablePrefixResolver::resolve($entityManager);

        $migrationEngine = new Engine(
            $entityManager,
            $tablePrefix,
            __DIR__,
            $plugin->getBundle()
        );
        $migrationEngine->up();

        $fieldModel = $factory->getModel('lead.field');
        \assert($fieldModel instanceof FieldModel);

        LastDoiDateFieldInstaller::install(
            $fieldModel,
            $factory->getTranslator(),
            $factory->getLogger(),
        );
    }
}
