<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Submission;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryMetadata;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Functional test for the entire cookie-based DOI skip scenario.
 * It covers both the "Set Phase" (getting the cookie on verification)
 * and the "Check Phase" (skipping DOI on a subsequent submission with the cookie).
 */
class DoiSkipCookieFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;
    private FormFixtureHelper $formFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $pluginFixtureHelper->createAndEnablePlugin();
        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    /**
     * This test covers the end-to-end flow:
     * 1. A user confirms their email and receives a browser proof cookie.
     * 2. The same user submits the form again.
     * 3. The plugin identifies the user via the cookie and skips the DOI process.
     */
    public function testCookieIsSetAndThenUsedToSkipVerification(): void
    {
        // -------------------------------------------------------------
        // Step 1: Setup Form and DOI Config with skip_on_cookie = true
        // -------------------------------------------------------------
        $form = $this->formFixtureHelper->createFormViaApi('Test Full Scenario Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success',
            skipOnCookie: true
        );
        $email = 'end-to-end-user@example.com';

        // -------------------------------------------------------------
        // --- PHASE 1: SET (First submission and verification) ---
        // -------------------------------------------------------------

        // 2. Simulate the FIRST form submission to create a pending DOI submission
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_testfullscenarioform]');
        $formElement = $formCrawler->form();
        $formElement->setValues(['mauticform[email]' => $email]);
        $this->client->submit($formElement);
        self::assertResponseIsSuccessful();

        // 3. Find the created DOI submission and verify it's pending
        $doiRepo = $this->em->getRepository(FormDoiSubmission::class);
        /** @var FormDoiSubmission $initialDoiSubmission */
        $initialDoiSubmission = $doiRepo->findOneBy(['email' => $email]);
        Assert::assertNotNull($initialDoiSubmission, 'Initial DOI Submission was not created.');
        Assert::assertSame('pending', $initialDoiSubmission->getStatus(), 'Initial submission should be pending.');

        // 4. Simulate the email verification click
        $hash  = $initialDoiSubmission->getHash();
        $token = base64_encode("{$form->getId()}:{$hash}");

        // Call the verification endpoint with the encoded token
        $verificationResponse = $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // Verify redirect to success URL
        $this->assertSame('https://example.com/success', $verificationResponse->getUri());

        // 5. Assert that the submission is now confirmed and the cookie is set
        /** @var FormDoiSubmission $confirmedDoiSubmission */
        $confirmedDoiSubmission = $doiRepo->find($initialDoiSubmission->getId());
        $browserProofToken      = $confirmedDoiSubmission->getBrowserProofToken();

        Assert::assertSame('confirmed', $confirmedDoiSubmission->getStatus());
        Assert::assertNotNull($browserProofToken, 'Browser proof token was not saved to DB.');
        Assert::assertSame(64, strlen($browserProofToken));

        /** @var Cookie $cookie */
        $cookie = $this->client->getCookieJar()->get('mautic_doi_receipt');
        Assert::assertNotNull($cookie, 'The mautic_doi_receipt cookie was not set.');
        Assert::assertSame($browserProofToken, $cookie->getValue(), 'Cookie value must match the token in DB.');
        Assert::assertTrue($cookie->isHttpOnly());
        Assert::assertTrue($cookie->isSecure(), 'Cookie should be secure for an HTTPS request.');

        // -------------------------------------------------------------
        // --- PHASE 2: CHECK (Second submission with cookie) ---
        // -------------------------------------------------------------

        // 6. Simulate the SECOND form submission (client still has the cookie)
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_testfullscenarioform]');
        $formElement = $formCrawler->form();
        $formElement->setValues(['mauticform[email]' => $email]);
        $this->client->submit($formElement);
        self::assertResponseIsSuccessful('Second form submission should be successful.');

        // 7. Assert that a NEW, SKIPPED DOI submission was created
        $allDoiSubmissions = $doiRepo->findBy(['email' => $email], ['id' => 'DESC']);
        Assert::assertCount(2, $allDoiSubmissions, 'There should be two DOI submissions in total.');

        /** @var FormDoiSubmission $skippedDoiSubmission */
        $skippedDoiSubmission = $allDoiSubmissions[0]; // The newest one

        Assert::assertSame(FormDoiSubmission::STATUS_SKIPPED, $skippedDoiSubmission->getStatus(), 'The new submission should have "skipped" status.');
        Assert::assertTrue($skippedDoiSubmission->isVerificationSkipped(), 'isVerificationSkipped flag should be true.');
        Assert::assertSame(FormDoiSubmission::SKIP_REASON_COOKIE_MATCH, $skippedDoiSubmission->getSkipReason(), 'Skip reason should be "cookie_match".');
        Assert::assertNotNull($skippedDoiSubmission->getDateConfirmed(), 'DateConfirmed should be set immediately on skip.');
        Assert::assertNotEquals($initialDoiSubmission->getId(), $skippedDoiSubmission->getId());

        $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
            'bundle'   => DoiVerificationHistoryMetadata::BUNDLE,
            'object'   => DoiVerificationHistoryMetadata::OBJECT,
            'objectId' => $skippedDoiSubmission->getId(),
            'action'   => 'skipped',
        ]);
        Assert::assertCount(1, $logs, 'Cookie-based skip should create a contact history entry.');

        // 8. Final sanity checks
        $coreSubmissionRepo = $this->em->getRepository(Submission::class);
        $leadRepo           = $this->em->getRepository(Lead::class);
        Assert::assertCount(2, $coreSubmissionRepo->findAll(), 'There should be two core form submissions.');
        Assert::assertCount(1, $leadRepo->findBy(['email' => $email]), 'There should still be only one contact.');
    }
}
