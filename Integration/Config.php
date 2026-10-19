<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Integration;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

class Config
{
    public const DEFAULT_FOLLOWUP_WAIT_TIME = 24;

    public const DEFAULT_DOI_LINK_TIMEOUT   = 48;

    public function __construct(
        private readonly IntegrationsHelper $integrationsHelper,
    ) {
    }

    public function isPublished(): bool
    {
        try {
            $integration = $this->getIntegrationEntity();

            return (bool) $integration->getIsPublished();
        } catch (IntegrationNotFoundException) {
            return false;
        }
    }

    public function getFollowUpWaitTime(): int
    {
        try {
            $integrationSettings = $this->getIntegrationEntity()->getFeatureSettings();
            assert(is_array($integrationSettings));

            $waitTime = $integrationSettings['integration']['followup_wait_time'] ?? null;

            if (is_numeric($waitTime) && (int) $waitTime >= 1) {
                return (int) $waitTime;
            }

            return self::DEFAULT_FOLLOWUP_WAIT_TIME;
        } catch (IntegrationNotFoundException) {
            return self::DEFAULT_FOLLOWUP_WAIT_TIME;
        }
    }

    public function getDoiLinkTimeout(): int
    {
        try {
            $integrationSettings = $this->getIntegrationEntity()->getFeatureSettings();
            assert(is_array($integrationSettings));

            $timeout = $integrationSettings['integration']['doi_link_timeout'] ?? null;

            if (is_numeric($timeout) && (int) $timeout >= 1) {
                return (int) $timeout;
            }

            return self::DEFAULT_DOI_LINK_TIMEOUT;
        } catch (IntegrationNotFoundException) {
            return self::DEFAULT_DOI_LINK_TIMEOUT;
        }
    }

    /**
     * @throws IntegrationNotFoundException
     */
    public function getIntegrationEntity(): Integration
    {
        $integrationObject = $this->integrationsHelper->getIntegration(LeuchtfeuerDoiIntegration::INTEGRATION_NAME);

        return $integrationObject->getIntegrationConfiguration();
    }
}
