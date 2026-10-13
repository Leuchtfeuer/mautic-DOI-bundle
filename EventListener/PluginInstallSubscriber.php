<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Migration\Engine;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\TablePrefixResolver;
use MauticPlugin\LeuchtfeuerDoiBundle\LeuchtfeuerDoiBundle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PluginInstallSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
        ];
    }

    public function onPluginInstall(PluginInstallEvent $event): void
    {
        if (!$event->checkContext(LeuchtfeuerDoiBundle::class)) {
            return;
        }

        // Resolve prefix from Form metadata so install honors MAUTIC_TABLE_PREFIX
        // even when the env var alone would leave migrations looking for unprefixed tables.
        $migrationEngine = new Engine(
            $this->em,
            TablePrefixResolver::resolve($this->em),
            dirname(__DIR__),
            'LeuchtfeuerDoiBundle'
        );

        $migrationEngine->up();
    }
}
