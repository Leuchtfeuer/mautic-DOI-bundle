<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Mautic\PageBundle\Event\UntrackableUrlsEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TokenGeneratorListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Config $pluginConfig,
    ) {
    }

    public function onEmailBuild(EmailBuilderEvent $event): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $tokens = $this->getCustomTokens();

        if ([] === $tokens) {
            return;
        }

        $tokenKeys = array_keys($tokens);

        // Check if any of our tokens are actually requested
        if (!$event->tokensRequested($tokenKeys)) {
            return;
        }

        $filteredTokens = $event->filterTokens($tokens);
        if ([] !== $filteredTokens) {
            $event->addTokens($filteredTokens);
        }
    }

    public function addNonTrackableToken(UntrackableUrlsEvent $event): void
    {
        if (!$this->pluginConfig->isPublished()) {
            return;
        }

        $event->addNonTrackable('{doi_link}');
    }

    /**
     * @return array<string, string>
     */
    private function getCustomTokens(): array
    {
        return [
            '{doi_link}'    => $this->translator->trans('mautic.plugin.doi.email.token.doi_link'),
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_ON_BUILD       => ['onEmailBuild', 0],
            PageEvents::REDIRECT_DO_NOT_TRACK => ['addNonTrackableToken', 0],
        ];
    }
}
