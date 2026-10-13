<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;

final class PluginFixtureHelper
{
    private const PLUGIN_BUNDLE     = 'LeuchtfeuerDoiBundle';
    private const INTEGRATION_NAME  = 'LeuchtfeuerDoi';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function createAndEnablePlugin(): void
    {
        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['bundle' => self::PLUGIN_BUNDLE]);
        if (!$plugin instanceof Plugin) {
            $plugin = new Plugin();
            $plugin->setName('DOI by Leuchtfeuer');
            $plugin->setBundle(self::PLUGIN_BUNDLE);
            $this->em->persist($plugin);
        }

        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => self::INTEGRATION_NAME]);
        if (!$integration instanceof Integration) {
            $integration = new Integration();
            $integration->setPlugin($plugin);
            $integration->setName(self::INTEGRATION_NAME);
            $this->em->persist($integration);
        }

        $integration->setPlugin($plugin);
        $integration->setIsPublished(true);
        $integration->setFeatureSettings(['integration' => [
            'followup_wait_time' => Config::DEFAULT_FOLLOWUP_WAIT_TIME,
            'doi_link_timeout'   => Config::DEFAULT_DOI_LINK_TIMEOUT,
        ]]);

        $this->em->flush();
    }

    public function disablePlugin(): void
    {
        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => self::INTEGRATION_NAME]);
        if (null === $integration) {
            return;
        }

        $integration->setIsPublished(false);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function modifyFollowupWaitTime(int $waitTime): void
    {
        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => self::INTEGRATION_NAME]);
        if (null === $integration) {
            return;
        }

        $featureSettings                                      = $integration->getFeatureSettings();
        $featureSettings['integration']['followup_wait_time'] = $waitTime;

        $integration->setFeatureSettings($featureSettings);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function modifyDoiLinkTimeout(int $timeout): void
    {
        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => self::INTEGRATION_NAME]);
        if (null === $integration) {
            return;
        }

        $featureSettings                                    = $integration->getFeatureSettings();
        $featureSettings['integration']['doi_link_timeout'] = $timeout;

        $integration->setFeatureSettings($featureSettings);
        $this->em->persist($integration);
        $this->em->flush();
    }
}
