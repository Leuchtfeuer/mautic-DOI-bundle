<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Command;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateTimeoutCommandFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private PluginFixtureHelper $pluginFixtureHelper;
    private FormFixtureHelper $formFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $this->pluginFixtureHelper->createAndEnablePlugin();
        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    public function testCommandMarksExpiredSubmissionsAsTimedOut(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        // Default timeout is 48 hours
        $expiredSubmission = $this->formFixtureHelper->createDoiSubmission($form, 'expired@example.com', new \DateTime('-49 hours'));
        $recentSubmission  = $this->formFixtureHelper->createDoiSubmission($form, 'recent@example.com', new \DateTime('-1 hour'));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout');

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 1 | Updated: 1', $output);

        $this->em->refresh($expiredSubmission);
        $this->em->refresh($recentSubmission);

        $this->assertTrue($expiredSubmission->isTimedOut());
        $this->assertSame(FormDoiSubmission::STATUS_TIMEOUT, $expiredSubmission->getStatus());
        $this->assertInstanceOf(\DateTime::class, $expiredSubmission->getDateTimeout());
        $this->assertTrue($recentSubmission->isPending());
        $this->assertSame(FormDoiSubmission::STATUS_PENDING, $recentSubmission->getStatus());
        $this->assertNotInstanceOf(\DateTime::class, $recentSubmission->getDateTimeout());
    }

    public function testCommandWithCustomLimit(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        $this->formFixtureHelper->createDoiSubmission($form, 'expired1@example.com', new \DateTime('-49 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired2@example.com', new \DateTime('-50 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired3@example.com', new \DateTime('-51 hours'));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout', ['--limit' => '2']);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 2 | Updated: 2', $output);
    }

    public function testCommandWithBatchSize(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        // Create 5 expired submissions
        $this->formFixtureHelper->createDoiSubmission($form, 'expired1@example.com', new \DateTime('-49 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired2@example.com', new \DateTime('-50 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired3@example.com', new \DateTime('-51 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired4@example.com', new \DateTime('-52 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired5@example.com', new \DateTime('-53 hours'));

        // Process with batch size of 2 - should process all 5 in 3 batches (2+2+1)
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout', ['--batch' => '2']);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 5 | Updated: 5', $output);
    }

    public function testCommandRespectsConfiguredTimeout(): void
    {
        // Modify the timeout to 12 hours instead of the default 48 hours
        $this->pluginFixtureHelper->modifyDoiLinkTimeout(12);

        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        // Create a submission that should be expired with 12h timeout but not with 48h timeout
        $expiredSubmission    = $this->formFixtureHelper->createDoiSubmission($form, 'expired@example.com', new \DateTime('-13 hours'));
        $notExpiredSubmission = $this->formFixtureHelper->createDoiSubmission($form, 'notexpired@example.com', new \DateTime('-11 hours'));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout');

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 1 | Updated: 1', $output);

        $this->em->refresh($expiredSubmission);
        $this->em->refresh($notExpiredSubmission);

        // The 13-hour-old submission should have been timed out
        $this->assertTrue($expiredSubmission->isTimedOut());
        // The 11-hour-old submission should still be pending
        $this->assertTrue($notExpiredSubmission->isPending());
    }

    public function testCommandSkipsAlreadyTimedOutSubmissions(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        // Create an already timed out submission
        $alreadyTimedOut = $this->formFixtureHelper->createDoiSubmission($form, 'already@example.com', new \DateTime('-49 hours'));
        $alreadyTimedOut->timeout();
        $this->em->persist($alreadyTimedOut);
        $this->em->flush();

        // Create a pending expired submission
        $pendingExpired = $this->formFixtureHelper->createDoiSubmission($form, 'pending@example.com', new \DateTime('-50 hours'));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout');

        $output = $commandTester->getDisplay();
        // Should only process the pending one, not the already timed out one
        $this->assertStringContainsString('Processed: 1 | Updated: 1', $output);

        $this->em->refresh($alreadyTimedOut);
        $this->em->refresh($pendingExpired);

        $this->assertTrue($alreadyTimedOut->isTimedOut());
        $this->assertTrue($pendingExpired->isTimedOut());
    }

    public function testCommandDisplaysMessageWhenPluginDisabled(): void
    {
        $this->pluginFixtureHelper->disablePlugin();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout');

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DOI plugin is disabled', $output);
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testVerboseOutputShowsSubmissionIds(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        $expired1 = $this->formFixtureHelper->createDoiSubmission($form, 'expired1@example.com', new \DateTime('-49 hours'));
        $expired2 = $this->formFixtureHelper->createDoiSubmission($form, 'expired2@example.com', new \DateTime('-50 hours'));
        $recent   = $this->formFixtureHelper->createDoiSubmission($form, 'recent@example.com', new \DateTime('-1 hour'));

        $kernel        = self::getContainer()->get('kernel');
        $application   = new Application($kernel);
        $command       = $application->find('leuchtfeuer:doi:update-timeout');
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Processed: 2 | Updated: 2', $output);
        $this->assertStringContainsString('Processed submission IDs:', $output);
        $this->assertStringContainsString('Updated submission IDs:', $output);

        $this->em->refresh($expired1);
        $this->em->refresh($expired2);
        $this->em->refresh($recent);

        $id1 = $expired1->getId();
        $id2 = $expired2->getId();
        $id3 = $recent->getId();

        $this->assertNotNull($id1);
        $this->assertNotNull($id2);

        // Both expired IDs must be present in the output
        $this->assertStringContainsString((string) $id1, $output);
        $this->assertStringContainsString((string) $id2, $output);

        // The recent (not processed) ID should not appear in verbose output
        if (null !== $id3) {
            $this->assertStringNotContainsString((string) $id3, $output);
        }
    }

    public function testVeryVerboseOutputShowsPerSubmissionLines(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        $expired1 = $this->formFixtureHelper->createDoiSubmission($form, 'expired1@example.com', new \DateTime('-49 hours'));
        $expired2 = $this->formFixtureHelper->createDoiSubmission($form, 'expired2@example.com', new \DateTime('-50 hours'));

        $kernel        = self::getContainer()->get('kernel');
        $application   = new Application($kernel);
        $command       = $application->find('leuchtfeuer:doi:update-timeout');
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE]);
        $output        = $commandTester->getDisplay();

        $this->em->refresh($expired1);
        $this->em->refresh($expired2);

        $id1 = $expired1->getId();
        $id2 = $expired2->getId();

        $this->assertNotNull($id1);
        $this->assertNotNull($id2);

        $this->assertStringContainsString(sprintf('Marked submission ID %s as timed out', (string) $id1), $output);
        $this->assertStringContainsString(sprintf('Marked submission ID %s as timed out', (string) $id2), $output);

        // Summary should still be present
        $this->assertStringContainsString('Processed: 2 | Updated: 2', $output);
    }

    public function testCommandWithNoPendingExpiredSubmissions(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout');

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 0 | Updated: 0', $output);
    }

    public function testCommandWithBatchSizeAndLimit(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Test DOI Form');

        // Create 7 expired submissions
        $this->formFixtureHelper->createDoiSubmission($form, 'expired1@example.com', new \DateTime('-49 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired2@example.com', new \DateTime('-50 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired3@example.com', new \DateTime('-51 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired4@example.com', new \DateTime('-52 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired5@example.com', new \DateTime('-53 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired6@example.com', new \DateTime('-54 hours'));
        $this->formFixtureHelper->createDoiSubmission($form, 'expired7@example.com', new \DateTime('-55 hours'));

        // Process with batch size of 3 and limit of 5 - should process 5 total (3+2)
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:update-timeout', [
            '--batch' => '3',
            '--limit' => '5',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Processed: 5 | Updated: 5', $output);
    }
}
