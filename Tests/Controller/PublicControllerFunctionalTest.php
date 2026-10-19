<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Submission;
use Mautic\LeadBundle\Entity\LeadEventLog;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryMetadata;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Component\HttpFoundation\Request;

final class PublicControllerFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;
    private FormFixtureHelper $formFixtureHelper;
    private PluginFixtureHelper $pluginFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $this->pluginFixtureHelper->createAndEnablePlugin();
        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    /**
     * Test successful email verification with redirect URL.
     */
    public function testVerifyEmailActionWithSuccessRedirectUrl(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/error'
        );

        // Submit the form:
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_testdoiform]');
        $formElement = $formCrawler->form();
        $formElement->setValues([
            'mauticform[email]' => 'lead@example.com',
        ]);
        $this->client->submit($formElement);
        $this->assertResponseIsSuccessful();

        // Ensure the submission was created properly.
        $submissions = $this->em->getRepository(Submission::class)->findAll();
        $this->assertCount(1, $submissions);

        $this->client->getResponse();
        $this->assertResponseIsSuccessful();

        // Find the DOI submission that should have been created
        $doiSubmissions = $this->em->getRepository(FormDoiSubmission::class)->findAll();
        $this->assertCount(1, $doiSubmissions);

        $doiSubmission = $doiSubmissions[0];
        $this->assertSame('pending', $doiSubmission->getStatus());
        $this->assertSame('lead@example.com', $doiSubmission->getEmail());

        // Create the token: base64("{formId}:{hash}")
        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $tokenData = "{$formId}:{$hash}";
        $token     = base64_encode($tokenData);

        // Call the verification endpoint with the encoded token
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // Verify redirect to success URL
        $this->assertSame('https://example.com/success', $verificationResponse->getUri());

        // Refresh the entity and verify it's now confirmed
        $this->em->refresh($doiSubmission);
        $this->assertSame('confirmed', $doiSubmission->getStatus());
        $this->assertInstanceOf(\DateTime::class, $doiSubmission->getDateConfirmed());

        // Verify a success history entry was created
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle' => DoiVerificationHistoryMetadata::BUNDLE,
            'object' => DoiVerificationHistoryMetadata::OBJECT,
        ]);
        $this->assertCount(1, $logs);
        $this->assertSame('success', $logs[0]->getAction());
    }

    /**
     * Test email verification with invalid token redirects to error URL.
     */
    public function testVerifyEmailActionWithErrorRedirectUrl(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form Error');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/error'
        );

        // Create an invalid token with valid form ID but invalid hash
        $invalidToken = base64_encode($form->getId().':invalid_hash');

        // Call the verification endpoint with an invalid token
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$invalidToken}");

        // Verify redirect to error URL
        $this->assertSame('https://example.com/error', $verificationResponse->getUri());
    }

    /**
     * Test that an expired DOI link redirects to error URL and sets status to timeout.
     */
    public function testVerifyEmailActionWithExpiredLinkRedirectsToErrorUrl(): void
    {
        // Set timeout to 1 hour for testing
        $this->pluginFixtureHelper->modifyDoiLinkTimeout(1);

        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Timeout Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/timeout-error'
        );

        // Create a DOI submission that was created 2 hours ago (expired)
        $expiredDate    = (new \DateTime())->modify('-2 hours');
        $doiSubmission  = $this->formFixtureHelper->createDoiSubmission($form, 'expired@example.com', $expiredDate);

        $this->assertSame('pending', $doiSubmission->getStatus());

        // Create the token
        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $tokenData = "{$formId}:{$hash}";
        $token     = base64_encode($tokenData);

        // Call the verification endpoint with the expired token
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // Verify redirect to error URL
        $this->assertSame('https://example.com/timeout-error', $verificationResponse->getUri());

        // Refresh and verify status is now timeout
        $this->em->refresh($doiSubmission);
        $this->assertSame(FormDoiSubmission::STATUS_TIMEOUT, $doiSubmission->getStatus());
        $this->assertInstanceOf(\DateTime::class, $doiSubmission->getDateTimeout());

        // Verify a failure history entry was created
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle' => DoiVerificationHistoryMetadata::BUNDLE,
            'object' => DoiVerificationHistoryMetadata::OBJECT,
        ]);
        $this->assertCount(1, $logs);
        $this->assertSame('failure', $logs[0]->getAction());
    }

    /**
     * Test that a valid (non-expired) DOI link still works correctly.
     */
    public function testVerifyEmailActionWithNonExpiredLinkSucceeds(): void
    {
        // Set timeout to 24 hours
        $this->pluginFixtureHelper->modifyDoiLinkTimeout(24);

        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Valid Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/error'
        );

        // Create a DOI submission that was created 1 hour ago (not expired with 24 hour timeout)
        $recentDate     = (new \DateTime())->modify('-1 hour');
        $doiSubmission  = $this->formFixtureHelper->createDoiSubmission($form, 'valid@example.com', $recentDate);

        $this->assertSame('pending', $doiSubmission->getStatus());

        // Create the token
        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $tokenData = "{$formId}:{$hash}";
        $token     = base64_encode($tokenData);

        // Call the verification endpoint
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // Verify redirect to success URL
        $this->assertSame('https://example.com/success', $verificationResponse->getUri());

        // Refresh and verify status is confirmed
        $this->em->refresh($doiSubmission);
        $this->assertSame(FormDoiSubmission::STATUS_CONFIRMED, $doiSubmission->getStatus());
        $this->assertInstanceOf(\DateTime::class, $doiSubmission->getDateConfirmed());

        // Verify a success history entry was created
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle' => DoiVerificationHistoryMetadata::BUNDLE,
            'object' => DoiVerificationHistoryMetadata::OBJECT,
        ]);
        $this->assertCount(1, $logs);
        $this->assertSame('success', $logs[0]->getAction());
    }

    /**
     * Test that an already timed-out submission returns error without rechecking expiration.
     */
    public function testVerifyEmailActionWithAlreadyTimedOutSubmissionReturnsError(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Already Timeout Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/already-timeout'
        );

        // Create a DOI submission and manually set it to timeout status
        $doiSubmission = $this->formFixtureHelper->createDoiSubmission($form, 'timeout@example.com', new \DateTime());
        $doiSubmission->timeout();
        $this->em->persist($doiSubmission);
        $this->em->flush();

        $this->assertSame(FormDoiSubmission::STATUS_TIMEOUT, $doiSubmission->getStatus());

        // Create the token
        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $tokenData = "{$formId}:{$hash}";
        $token     = base64_encode($tokenData);

        // Call the verification endpoint
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // Verify redirect to error URL
        $this->assertSame('https://example.com/already-timeout', $verificationResponse->getUri());

        // Status should still be timeout
        $this->em->refresh($doiSubmission);
        $this->assertSame(FormDoiSubmission::STATUS_TIMEOUT, $doiSubmission->getStatus());

        // Verify a failure history entry was created (and only one — deduplication)
        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle' => DoiVerificationHistoryMetadata::BUNDLE,
            'object' => DoiVerificationHistoryMetadata::OBJECT,
        ]);
        $this->assertCount(1, $logs);
        $this->assertSame('failure', $logs[0]->getAction());
    }

    public function testVerifyEmailActionDeduplicatesHistoryEntries(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Dedup Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            errorRedirectUrl: 'https://example.com/error'
        );

        $doiSubmission = $this->formFixtureHelper->createDoiSubmission($form, 'dedup@example.com', new \DateTime());
        $doiSubmission->timeout();
        $this->em->persist($doiSubmission);
        $this->em->flush();

        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $token     = base64_encode("{$formId}:{$hash}");

        // Click twice on the timed-out link
        $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");
        $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle' => DoiVerificationHistoryMetadata::BUNDLE,
            'object' => DoiVerificationHistoryMetadata::OBJECT,
        ]);
        $this->assertCount(1, $logs, 'Only one history entry should exist even after multiple clicks');
    }
}
