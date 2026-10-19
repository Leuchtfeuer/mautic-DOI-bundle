<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * Functional tests for the custom post-action feature when DOI is skipped for a known contact.
 */
final class DoiSkippedSubmitActionFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;
    private FormFixtureHelper $formFixtureHelper;
    private const CUSTOM_REDIRECT_URL      = '/custom-redirect-success';
    private const STANDARD_REDIRECT_URL    = '/standard-redirect';
    private const CUSTOM_MESSAGE           = 'This is the custom skip message.';
    private const STANDARD_MESSAGE         = 'This is the standard message.';

    private const SKIP_CONDITIONS          = [
        [
            'glue'       => 'and',
            'operator'   => 'contains',
            'properties' => [
                'filter' => '@example.com',
            ],
            'field'  => 'email',
            'type'   => 'email',
            'object' => 'lead',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $pluginFixtureHelper->createAndEnablePlugin();
        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    /**
     * Verifies that the custom "skip action" redirect overrides the standard form redirect
     * when a known contact (with a valid cookie) submits the form.
     */
    public function testSkipActionRedirectsToCustomUrlWhenVerificationIsSkipped(): void
    {
        // 1. Arrange
        $email = 'redirect-test@example.com';

        $form = $this->formFixtureHelper->createFormViaApi('Test Skip Redirect Form');
        $form->setIsPublished(true);
        $form->setPostAction('redirect');
        $form->setPostActionProperty(self::STANDARD_REDIRECT_URL);
        $this->em->flush();

        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            skipPostAction: 'redirect',
            skipPostActionProperty: self::CUSTOM_REDIRECT_URL,
            skipConditions: self::SKIP_CONDITIONS,
        );

        // 2. Act (Phase 2): Submit the form again with the cookie.
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_testskipredirectform]');
        $formElement = $formCrawler->form();
        $formElement->setValues(['mauticform[email]' => $email]);
        $this->client->submit($formElement);

        // 3. Assert
        $this->assertSame(self::CUSTOM_REDIRECT_URL, $this->client->getRequest()->getPathInfo(), 'Should have been redirected to the custom skip action URL.');
    }

    /**
     * Verifies that the custom "skip action" message overrides the standard form message
     * when submitting via AJAX with a valid cookie.
     */
    public function testSkipActionDisplaysCustomMessageWhenSkipped(): void
    {
        // 1. Arrange
        $email = 'message-test@example.com';

        $form = $this->formFixtureHelper->createFormViaApi('Test Skip Message Form');
        $form->setIsPublished(true);
        $form->setPostAction('message');
        $form->setPostActionProperty(self::STANDARD_MESSAGE);
        $this->em->flush();

        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            skipPostAction: 'message',
            skipPostActionProperty: self::CUSTOM_MESSAGE,
            skipConditions: self::SKIP_CONDITIONS,
        );

        // 2. Act (Phase 2): Submit the form
        $payload = [
            'mauticform' => [
                'email'     => $email,
                'formId'    => $form->getId(),
                'formName'  => $form->getAlias(),
                'messenger' => 1,
            ],
        ];
        $this->client->request(Request::METHOD_POST, "/form/submit?formId={$form->getId()}", $payload);
        $response = $this->client->getResponse();
        $content  = $response->getContent();

        // 3. Assert
        $this->assertTrue($response->isOk(), 'Response should be successful.');

        // Decode Unicode escapes (e.g., \u0022 -> ")
        $decodedContent = str_replace('\u0020', ' ', $content);
        $this->assertStringContainsString(self::CUSTOM_MESSAGE, $decodedContent);
        $this->assertStringNotContainsString(self::STANDARD_MESSAGE, $decodedContent);
    }

    /**
     * Verifies that the standard form action is used as a fallback when the user has a valid cookie
     * but the custom skip action itself is not configured.
     */
    public function testStandardFormActionIsUsedWhenSkipActionIsDisabled(): void
    {
        // 1. Arrange
        $email = 'fallback-test@example.com';

        $form = $this->formFixtureHelper->createFormViaApi('Test Fallback Form');
        $form->setIsPublished(true);
        $form->setPostAction('redirect');
        $form->setPostActionProperty(self::STANDARD_REDIRECT_URL);
        $this->em->flush();

        // Note: skipPostAction is NOT set, enabling the fallback.
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            skipConditions: self::SKIP_CONDITIONS,
        );

        // 2. Act (Phase 2): Submit the form again with the cookie
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_testfallbackform]');
        $formElement = $formCrawler->form();
        $formElement->setValues(['mauticform[email]' => $email]);
        $this->client->submit($formElement);

        // 3. Assert
        $this->assertSame(self::STANDARD_REDIRECT_URL, $this->client->getRequest()->getPathInfo(), 'Should have been redirected to the standard form action URL.');
    }

    /**
     * Verifies that the custom "skip action" message overrides the standard form redirect
     * when a known contact submits the form via AJAX.
     */
    public function testSkipActionMessageOverridesStandardRedirect(): void
    {
        // 1. Arrange
        $email = 'redirect-override-test@example.com';

        $form = $this->formFixtureHelper->createFormViaApi('Test Skip Message Over Redirect Form');
        $form->setIsPublished(true);
        // Default action is a redirect
        $form->setPostAction('redirect');
        $form->setPostActionProperty(self::STANDARD_REDIRECT_URL);
        $this->em->flush();

        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            // But the skip action is a message
            skipPostAction: 'message',
            skipPostActionProperty: self::CUSTOM_MESSAGE,
            skipConditions: self::SKIP_CONDITIONS,
        );

        // 2. Act (Phase 2): Submit the form via AJAX
        $payload = [
            'mauticform' => [
                'email'     => $email,
                'formId'    => $form->getId(),
                'formName'  => $form->getAlias(),
                'messenger' => 1, // Indicates an AJAX submission that expects a postMessage response
            ],
        ];
        $this->client->request(Request::METHOD_POST, "/form/submit?formId={$form->getId()}", $payload);
        $response = $this->client->getResponse();
        $content  = $response->getContent();

        // 3. Assert
        $this->assertTrue($response->isOk(), 'Response should be successful.');
        $this->assertFalse($response->isRedirect(), 'Response should not be an HTTP redirect.');

        // For AJAX requests, Mautic returns HTML with a postMessage script. We need to parse the JSON from it.
        preg_match('/parent\\.postMessage\\("(.+)",/U', $content, $matches);
        $this->assertArrayHasKey(1, $matches, 'Could not find postMessage payload in the response.');

        // The captured group is a string-escaped JSON payload with Unicode sequences (\uXXXX).
        // To correctly decode it, we perform a two-step process.
        // 1. Wrap the string in quotes to make it a valid JSON string literal.
        $jsonStringLiteral = '"'.$matches[1].'"';

        // 2. The first decode resolves the Unicode sequences (e.g., \u007B -> {) into a single string.
        $decodedJsonString = json_decode($jsonStringLiteral);
        $this->assertIsString($decodedJsonString, 'First JSON decode should result in a string.');

        // 3. The second decode parses the now-valid JSON string into a PHP array.
        $postMessagePayload = json_decode($decodedJsonString, true);
        $this->assertIsArray($postMessagePayload, "The postMessage payload could not be decoded into an array. JSON: {$decodedJsonString}");

        // The core assertion: The message action should *replace* the redirect action in the response data.
        $this->assertNull($postMessagePayload['redirect'], 'The "redirect" key should be null".');
        $this->assertArrayHasKey('successMessage', $postMessagePayload, 'The "successMessage" key should be present.');
        $this->assertSame(self::CUSTOM_MESSAGE, $postMessagePayload['successMessage'], 'The success message should be the custom skip message.');
    }

    /**
     * Verifies that the custom "skip action" hideform hides the form and displays the configured message
     * when a known contact submits the form via AJAX.
     */
    public function testSkipActionHidesFormWithCustomMessage(): void
    {
        // 1. Arrange
        $email = 'hideform-test@example.com';

        $form = $this->formFixtureHelper->createFormViaApi('Test Skip Hide Form');
        $form->setIsPublished(true);
        // Default action is a message
        $form->setPostAction('message');
        $form->setPostActionProperty(self::STANDARD_MESSAGE);
        $this->em->flush();

        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            // But the skip action is hideform
            skipPostAction: 'hideform',
            skipPostActionProperty: self::CUSTOM_MESSAGE,
            skipConditions: self::SKIP_CONDITIONS,
        );

        // 2. Act (Phase 2): Submit the form via AJAX
        $payload = [
            'mauticform' => [
                'email'     => $email,
                'formId'    => $form->getId(),
                'formName'  => $form->getAlias(),
                'messenger' => 1,
            ],
        ];
        $this->client->request(Request::METHOD_POST, "/form/submit?formId={$form->getId()}", $payload);
        $response = $this->client->getResponse();
        $content  = $response->getContent();

        // 3. Assert
        $this->assertTrue($response->isOk(), 'Response should be successful.');
        $this->assertFalse($response->isRedirect(), 'Response should not be an HTTP redirect.');

        // For AJAX requests, Mautic returns HTML with a postMessage script. We need to parse the JSON from it.
        preg_match('/parent\\.postMessage\\("(.+)",/U', $content, $matches);
        $this->assertArrayHasKey(1, $matches, 'Could not find postMessage payload in the response.');

        // The captured group is a string-escaped JSON payload with Unicode sequences (\uXXXX).
        // To correctly decode it, we perform a two-step process.
        // 1. Wrap the string in quotes to make it a valid JSON string literal.
        $jsonStringLiteral = '"'.$matches[1].'"';

        // 2. The first decode resolves the Unicode sequences (e.g., \u007B -> {) into a single string.
        $decodedJsonString = json_decode($jsonStringLiteral);
        $this->assertIsString($decodedJsonString, 'First JSON decode should result in a string.');

        // 3. The second decode parses the now-valid JSON string into a PHP array.
        $postMessagePayload = json_decode($decodedJsonString, true);
        $this->assertIsArray($postMessagePayload, "The postMessage payload could not be decoded into an array. JSON: {$decodedJsonString}");

        // The core assertion: The hideform action should hide the form and show the custom message.
        $this->assertTrue($postMessagePayload['hideform'], 'The "hideform" key should be true.');
        $this->assertArrayHasKey('successMessage', $postMessagePayload, 'The "successMessage" key should be present.');
        $this->assertSame(self::CUSTOM_MESSAGE, $postMessagePayload['successMessage'], 'The success message should be the custom skip message.');
    }
}
