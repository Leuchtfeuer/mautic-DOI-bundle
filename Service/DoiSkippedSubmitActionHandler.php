<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\FormBundle\Event\SubmissionEvent;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DoiSkippedSubmitActionHandler
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Process the custom skip action and set the appropriate response.
     */
    public function processSkipAction(SubmissionEvent $event, FormDoiConfig $doiConfig): void
    {
        $skipAction   = $doiConfig->getSkipPostAction();
        $skipProperty = $doiConfig->getSkipPostActionProperty();

        if (empty($skipAction)) {
            return;
        }

        // Determine request mode (AJAX or messenger)
        $request        = $event->getRequest();
        $post           = $request->request->all()['mauticform'] ?? [];
        $isAjax         = null !== $request->query->get('ajax');
        $messengerMode  = !empty($post['messenger']);
        $asArrayPayload = $isAjax || $messengerMode;

        // Replace tokens first
        $processedProperty = $this->replaceTokens((string) $skipProperty, $event);

        $response = match ($skipAction) {
            'redirect' => $this->createRedirectResponse($processedProperty, $asArrayPayload),
            'message'  => $this->createMessageResponse($processedProperty, $asArrayPayload),
            'return'   => $this->createReturnResponse($processedProperty, $event),
            'hideform' => $this->createHideResponse($processedProperty, $asArrayPayload),
            default    => null,
        };

        if (null !== $response) {
            // Let Mautic handle this response
            $event->setPostSubmitResponse($response);
            $this->eventDispatcher->dispatch($event, DoiEvents::DOI_ON_SET_SKIP_POST_ACTION_RESPONSE);

            // Stop propagation so the controller knows a custom response is set
            $event->stopPropagation();
        }
    }

    /**
     * Return array payload in AJAX/messenger mode to override default successMessage,
     * otherwise return a Symfony Response for normal requests.
     *
     * @return Response|array<string, mixed>|null
     */
    private function createRedirectResponse(?string $url, bool $asArrayPayload): Response|array|null
    {
        if (empty($url)) {
            return null;
        }

        if ($asArrayPayload) {
            // Payload merges into the controller's default response.
            // Overwrite 'redirect' with null to stop redirect if form action was 'redirect'.
            // successMessage must be an array for implode().
            return [
                'successMessage' => [],
                'hideform_text'  => '',
                'hideform'       => false,
                'redirect'       => $url,
            ];
        }

        return new RedirectResponse($url);
    }

    /**
     * Return array payload in AJAX/messenger mode to override default successMessage,
     * otherwise return a Symfony Response for normal requests.
     *
     * @return Response|array<string, mixed>
     */
    private function createMessageResponse(?string $message, bool $asArrayPayload): Response|array
    {
        if (empty($message)) {
            $message = $this->translator->trans('leuchtfeuer.doi.verification_skipped.default_message');
        }

        if ($asArrayPayload) {
            // Payload merges into the controller's default response.
            // Overwrite 'redirect' with null to stop redirect if form action was 'redirect'.
            // successMessage must be an array for implode().
            return [
                'successMessage' => [$message],
                'redirect'       => null,
            ];
        }

        $this->requestStack->getSession()->set('mautic.emailbundle.message', ['message' => $message]);

        return new RedirectResponse($this->urlGenerator->generate('mautic_form_postmessage'));
    }

    /**
     * Support for external hideform post-action.
     *
     * @return Response|array<string, mixed>
     */
    private function createHideResponse(?string $message, bool $asArrayPayload): Response|array
    {
        if (empty($message)) {
            $message = $this->translator->trans('leuchtfeuer.doi.verification_skipped.default_message');
        }

        if ($asArrayPayload) {
            return [
                'successMessage' => [],
                'hideform_text'  => $message,
                'hideform'       => true,
            ];
        }

        $this->requestStack->getSession()->set('mautic.emailbundle.message', ['message' => $message]);

        return new RedirectResponse($this->urlGenerator->generate('mautic_form_postmessage'));
    }

    /**
     * Creates a redirect response back to the referring page, mirroring Mautic's 'return' action.
     */
    private function createReturnResponse(?string $message, SubmissionEvent $event): Response
    {
        $request = $event->getRequest();
        $post    = $request->request->all()['mauticform'] ?? [];
        $server  = $request->server->all();

        $returnUrl = $post['return'] ?? $server['HTTP_REFERER'] ?? null;

        if (null === $returnUrl) {
            // Fall back to a message if no referrer/return param exists
            return $this->createMessageResponse($message, false);
        }

        if (!empty($message)) {
            $query     = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $query.'mauticMessage='.rawurlencode($message);
        }

        return new RedirectResponse($returnUrl);
    }

    /**
     * Replaces Mautic tokens in a given string (e.g., URL or message).
     */
    private function replaceTokens(string $string, SubmissionEvent $event): string
    {
        $tokens = $event->getTokens();

        foreach ($tokens as $token => $value) {
            $string = str_replace(sprintf('{%s}', $token), (string) $value, $string);
        }

        return $string;
    }
}
