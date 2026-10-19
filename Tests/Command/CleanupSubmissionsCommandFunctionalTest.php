<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupSubmissionsCommandFunctionalTest extends MauticMysqlTestCase
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

    // =========================================================================
    // Test Case: Cleanup enabled - timed-out submissions are deleted immediately
    // =========================================================================

    public function testTimedOutSubmissionIsDeletedImmediately(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create a submission that timed out (even recently)
        $timedOutSubmission = $this->formFixtureHelper->createDoiSubmission(
            $form,
            'timedout@example.com',
            new \DateTime('-30 days') // Created 30 days ago
        );
        // Mark as timed out recently
        $timedOutSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $timedOutSubmission->setDateTimeout(new \DateTime('-1 hour'));
        $this->em->persist($timedOutSubmission);
        $this->em->flush();

        $doiSubmissionId  = $timedOutSubmission->getId();
        $coreSubmissionId = $timedOutSubmission->getFormSubmission()->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submission', $output);

        // Verify DOI submission is deleted
        $this->em->clear();
        $deletedDoiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedDoiSubmission, 'DOI submission should be deleted');

        // Verify core submission is deleted
        $deletedCoreSubmission = $this->em->getRepository(Submission::class)->find($coreSubmissionId);
        $this->assertNotInstanceOf(Submission::class, $deletedCoreSubmission, 'Core submission should be deleted');
    }

    public function testPendingSubmissionIsNotDeleted(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create an old pending submission (never timed out)
        $pendingSubmission = $this->formFixtureHelper->createDoiSubmission(
            $form,
            'pending@example.com',
            new \DateTime('-30 days') // Created 30 days ago but still pending
        );
        $doiSubmissionId = $pendingSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 0 submission', $output);

        // Verify pending submission still exists (only timed-out submissions are cleaned up)
        $this->em->clear();
        $existingSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission, 'Pending submission should not be deleted');
        $this->assertTrue($existingSubmission->isPending(), 'Submission should remain pending');
    }

    public function testConfirmedSubmissionIsNotDeleted(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create an old but confirmed submission
        $confirmedSubmission = $this->formFixtureHelper->createDoiSubmission(
            $form,
            'confirmed@example.com',
            new \DateTime('-30 days')
        );
        $confirmedSubmission->confirm();
        $this->em->persist($confirmedSubmission);
        $this->em->flush();
        $doiSubmissionId = $confirmedSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 0 submission', $output);

        // Verify confirmed submission still exists
        $this->em->clear();
        $existingSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission, 'Confirmed submission should not be deleted');
        $this->assertTrue($existingSubmission->isConfirmed(), 'Submission should remain confirmed');
    }

    // =========================================================================
    // Test Case: deleteAfterTimeout = false (never delete)
    // =========================================================================

    public function testTimedOutSubmissionRemainsWhenCleanupNotEnabled(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('No Cleanup Form');
        // Create DOI config WITHOUT cleanup enabled (deleteAfterTimeout = false)
        $this->createDoiConfigWithoutCleanup($form);

        // Create a timed-out submission (even very old ones should not be deleted)
        $oldSubmission = $this->formFixtureHelper->createDoiSubmission(
            $form,
            'old@example.com',
            new \DateTime('-60 days')
        );
        $oldSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $oldSubmission->setDateTimeout(new \DateTime('-30 days'));
        $this->em->persist($oldSubmission);
        $this->em->flush();

        $doiSubmissionId = $oldSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('No forms have cleanup enabled', $output);

        // Verify submission still exists
        $this->em->clear();
        $existingSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission, 'Submission should remain when cleanup is not enabled');
        $this->assertSame(FormDoiSubmission::STATUS_TIMEOUT, $existingSubmission->getStatus());
    }

    /**
     * If contact was created by the form submission and is not known
     * to the system before this submission, the entire contact should be deleted.
     */
    public function testNewContactIsDeletedWithSubmission(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create submission with a NEW contact that timed out
        $timedOutSubmission = $this->createTimedOutDoiSubmissionWithNewContact(
            $form,
            'newcontact@example.com',
            new \DateTime('-30 days'), // Created 30 days ago
            new \DateTime('-1 day')    // Timed out 1 day ago
        );
        $contactId       = $timedOutSubmission->getLead()->getId();
        $doiSubmissionId = $timedOutSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submission', $output);

        // Verify submission is deleted
        $this->em->clear();
        $deletedSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedSubmission, 'DOI submission should be deleted');

        // Verify NEW contact is also deleted
        $deletedContact = $this->em->getRepository(Lead::class)->find($contactId);
        $this->assertNull($deletedContact?->getId(), 'New contact should be deleted with the submission');
    }

    /**
     * If contact was created by this submission but has been active after the submission,
     * only the submission is deleted; the contact remains.
     */
    public function testNewContactWithActivityAfterSubmissionIsNotDeleted(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create submission with a NEW contact that timed out
        $timedOutSubmission = $this->createTimedOutDoiSubmissionWithNewContact(
            $form,
            'activecontact@example.com',
            new \DateTime('-30 days'), // Created 30 days ago
            new \DateTime('-1 day')    // Timed out 1 day ago
        );
        $contact   = $timedOutSubmission->getLead();
        $this->assertInstanceOf(Lead::class, $contact);
        $contactId = $contact->getId();

        // Simulate activity after the submission (e.g. visited a page 10 days ago)
        $contact->setLastActive(new \DateTime('-10 days'));
        $this->em->persist($contact);
        $this->em->flush();

        $doiSubmissionId = $timedOutSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submission', $output);

        // Verify submission is deleted
        $this->em->clear();
        $deletedSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedSubmission, 'DOI submission should be deleted');

        // Verify contact is NOT deleted because it had activity after submission
        $existingContact = $this->em->getRepository(Lead::class)->find($contactId);
        $this->assertInstanceOf(Lead::class, $existingContact, 'Contact with activity after submission should NOT be deleted');
        $this->assertSame('activecontact@example.com', $existingContact->getEmail());
    }

    /**
     * If contact was already known to the system before this submission,
     * only the submission is deleted; the contact remains.
     */
    public function testPreviouslyKnownContactRemainsAfterSubmissionDeletion(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Cleanup Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create an existing contact FIRST (2 months ago)
        $existingContact = $this->formFixtureHelper->createContact('knowncontact@example.com');
        // Backdate contact creation to 2 months ago
        $existingContact->setDateAdded(new \DateTime('-60 days'));
        $this->em->persist($existingContact);
        $this->em->flush();
        $contactId = $existingContact->getId();

        // Create a timed-out submission linked to this EXISTING contact
        $timedOutSubmission = $this->createTimedOutDoiSubmissionForExistingContact(
            $form,
            $existingContact,
            new \DateTime('-30 days'), // Created 30 days ago
            new \DateTime('-1 day')    // Timed out 1 day ago
        );
        $doiSubmissionId = $timedOutSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submission', $output);

        // Verify submission is deleted
        $this->em->clear();
        $deletedSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedSubmission, 'DOI submission should be deleted');

        // Verify KNOWN contact still exists
        $existingContactReloaded = $this->em->getRepository(Lead::class)->find($contactId);
        $this->assertInstanceOf(Lead::class, $existingContactReloaded, 'Previously known contact should NOT be deleted');
        $this->assertSame('knowncontact@example.com', $existingContactReloaded->getEmail());
    }

    /**
     * If contact has other active DOI submissions (pending or confirmed), the contact should NOT be deleted
     * even if it was created by this submission.
     *
     * Scenario: Contact submits form1, doesn't confirm, times out. During the cleanup grace period,
     * contact submits form2. When cleanup runs, only the timed-out submission should be deleted;
     * the contact should remain because they have another active submission.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideOtherSubmissionStatuses')]
    public function testContactWithOtherActiveSubmissionsIsNotDeleted(string $otherStatus): void
    {
        $form1 = $this->formFixtureHelper->createFormViaApi('Form One');
        $form2 = $this->formFixtureHelper->createFormViaApi('Form Two');

        $this->createDoiConfigWithCleanup($form1);
        $this->createDoiConfigWithCleanup($form2);

        // Create a NEW contact with a timed-out submission on form1
        $timedOutSubmission = $this->createTimedOutDoiSubmissionWithNewContact(
            $form1,
            'multisubmit@example.com',
            new \DateTime('-30 days'), // Created 30 days ago - this sets the contact's dateIdentified
            new \DateTime('-1 day')    // Timed out 1 day ago
        );
        $contact              = $timedOutSubmission->getLead();
        $contactId            = $contact->getId();
        $timedOutSubmissionId = $timedOutSubmission->getId();

        // Create a second submission on form2 for the same contact
        $otherSubmission = $this->createDoiSubmissionForExistingContact(
            $form2,
            $contact,
            new \DateTime('-5 days')
        );

        if (FormDoiSubmission::STATUS_CONFIRMED === $otherStatus) {
            $otherSubmission->confirm();
            $this->em->persist($otherSubmission);
            $this->em->flush();
        }

        $otherSubmissionId = $otherSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        // Only the timed-out submission should be deleted
        $this->assertStringContainsString('Submissions deleted: 1', $output);

        // Verify timed-out submission is deleted
        $this->em->clear();
        $deletedSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($timedOutSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedSubmission, 'Timed-out DOI submission should be deleted');

        // Verify other submission still exists
        $existingSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($otherSubmissionId);
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission, ucfirst($otherStatus).' submission should still exist');
        $this->assertSame($otherStatus, $existingSubmission->getStatus());

        // Verify contact is NOT deleted (has other active submission)
        $existingContact = $this->em->getRepository(Lead::class)->find($contactId);
        $this->assertInstanceOf(Lead::class, $existingContact, "Contact with {$otherStatus} submission should NOT be deleted");
        $this->assertSame('multisubmit@example.com', $existingContact->getEmail());
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function provideOtherSubmissionStatuses(): \Generator
    {
        yield 'pending' => [FormDoiSubmission::STATUS_PENDING];
        yield 'confirmed' => [FormDoiSubmission::STATUS_CONFIRMED];
    }

    // =========================================================================
    // Command options tests
    // =========================================================================

    public function testLimitOption(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Limit Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create 3 timed-out submissions with unique emails
        $this->createTimedOutDoiSubmission($form, 'limitopt1@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));
        $this->createTimedOutDoiSubmission($form, 'limitopt2@example.com', new \DateTime('-31 days'), new \DateTime('-2 days'));
        $this->createTimedOutDoiSubmission($form, 'limitopt3@example.com', new \DateTime('-32 days'), new \DateTime('-3 days'));

        // Verify all 3 submissions exist before running command
        $this->em->clear();
        $allTimedOutCount = $this->em->getRepository(FormDoiSubmission::class)->count([
            'form'   => $form->getId(),
            'status' => FormDoiSubmission::STATUS_TIMEOUT,
        ]);
        $this->assertSame(3, $allTimedOutCount, 'Should have 3 timed-out submissions before cleanup');

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--limit' => '2']);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Submissions deleted: 2', $output);

        // Verify only 1 submission remains
        $this->em->clear();
        $remainingCount = $this->em->getRepository(FormDoiSubmission::class)->count([
            'form'   => $form->getId(),
            'status' => FormDoiSubmission::STATUS_TIMEOUT,
        ]);
        $this->assertSame(1, $remainingCount, 'Should have 1 timed-out submission after cleanup with limit 2');
    }

    public function testBatchOption(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Batch Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create 5 timed-out submissions
        for ($i = 1; $i <= 5; ++$i) {
            $this->createTimedOutDoiSubmission(
                $form,
                "batch{$i}@example.com",
                new \DateTime('-'.(30 + $i).' days'), // Created 30+ days ago
                new \DateTime('-'.$i.' days')         // Timed out 1-5 days ago
            );
        }

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--batch' => '2']);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 5 submission', $output);
    }

    public function testFormIdFilterOption(): void
    {
        $form1 = $this->formFixtureHelper->createFormViaApi('Form One');
        $form2 = $this->formFixtureHelper->createFormViaApi('Form Two');

        $this->createDoiConfigWithCleanup($form1);
        $this->createDoiConfigWithCleanup($form2);

        $submission1 = $this->createTimedOutDoiSubmission($form1, 'form1@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));
        $submission2 = $this->createTimedOutDoiSubmission($form2, 'form2@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));

        $submission1Id = $submission1->getId();
        $submission2Id = $submission2->getId();

        // Only cleanup form1
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--form-id' => $form1->getId()]);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submissions', $output);

        // Verify only form1 submission is deleted
        $this->em->clear();
        $deletedSubmission1  = $this->em->getRepository(FormDoiSubmission::class)->find($submission1Id);
        $existingSubmission2 = $this->em->getRepository(FormDoiSubmission::class)->find($submission2Id);

        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedSubmission1, 'Form1 submission should be deleted');
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission2, 'Form2 submission should remain');
    }

    public function testInvalidFormIdOption(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Valid Form');
        $this->createDoiConfigWithCleanup($form);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--form-id' => '99999']);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('does not have DOI cleanup enabled', $output);
    }

    public function testCommandFailsWhenPluginDisabled(): void
    {
        $this->pluginFixtureHelper->disablePlugin();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('DOI plugin is disabled', $output);
    }

    // =========================================================================
    // Verbose output tests
    // =========================================================================

    public function testVerboseOutputShowsDeletedIds(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Verbose Test Form');
        $this->createDoiConfigWithCleanup($form);

        $submission1 = $this->createTimedOutDoiSubmission($form, 'verbose1@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));
        $submission2 = $this->createTimedOutDoiSubmission($form, 'verbose2@example.com', new \DateTime('-31 days'), new \DateTime('-2 days'));

        $id1 = $submission1->getId();
        $id2 = $submission2->getId();

        $kernel        = self::getContainer()->get('kernel');
        $application   = new Application($kernel);
        $command       = $application->find('leuchtfeuer:doi:cleanup-submissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString((string) $id1, $output);
        $this->assertStringContainsString((string) $id2, $output);
    }

    public function testVeryVerboseOutputShowsPerSubmissionDetails(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Very Verbose Test Form');
        $this->createDoiConfigWithCleanup($form);

        $submission   = $this->createTimedOutDoiSubmission($form, 'veryverbose@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));
        $submissionId = $submission->getId();

        $kernel        = self::getContainer()->get('kernel');
        $application   = new Application($kernel);
        $command       = $application->find('leuchtfeuer:doi:cleanup-submissions');
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE]);
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted DOI submission ID', $output);
        $this->assertStringContainsString((string) $submissionId, $output);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testMultipleFormsWithCleanupEnabled(): void
    {
        $formWithCleanup    = $this->formFixtureHelper->createFormViaApi('Form With Cleanup');
        $formWithoutCleanup = $this->formFixtureHelper->createFormViaApi('Form Without Cleanup');

        $this->createDoiConfigWithCleanup($formWithCleanup);
        $this->createDoiConfigWithoutCleanup($formWithoutCleanup);

        // Both submissions timed out
        $submissionCleanup   = $this->createTimedOutDoiSubmission($formWithCleanup, 'cleanup@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));
        $submissionNoCleanup = $this->createTimedOutDoiSubmission($formWithoutCleanup, 'nocleanup@example.com', new \DateTime('-30 days'), new \DateTime('-1 day'));

        $idCleanup   = $submissionCleanup->getId();
        $idNoCleanup = $submissionNoCleanup->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submissions', $output);

        // Verify only the form with cleanup enabled has its submission deleted
        $this->em->clear();
        $deletedCleanup    = $this->em->getRepository(FormDoiSubmission::class)->find($idCleanup);
        $existingNoCleanup = $this->em->getRepository(FormDoiSubmission::class)->find($idNoCleanup);

        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedCleanup, 'Form with cleanup enabled should have submission deleted');
        $this->assertInstanceOf(FormDoiSubmission::class, $existingNoCleanup, 'Form without cleanup should retain submission');
    }

    public function testSkippedSubmissionsAreNotDeleted(): void
    {
        $form = $this->formFixtureHelper->createFormViaApi('Skipped Test Form');
        $this->createDoiConfigWithCleanup($form);

        // Create an expired but skipped submission
        $skippedSubmission = $this->formFixtureHelper->createDoiSubmission(
            $form,
            'skipped@example.com',
            new \DateTime('-8 days')
        );
        $skippedSubmission->skip(FormDoiSubmission::SKIP_REASON_COOKIE_MATCH);
        $this->em->persist($skippedSubmission);
        $this->em->flush();
        $doiSubmissionId = $skippedSubmission->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 0 submission', $output);

        // Verify skipped submission still exists
        $this->em->clear();
        $existingSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertInstanceOf(FormDoiSubmission::class, $existingSubmission, 'Skipped submission should not be deleted');
        $this->assertSame(FormDoiSubmission::STATUS_SKIPPED, $existingSubmission->getStatus());
    }

    public function testUploadedFileIsDeletedWithSubmission(): void
    {
        $form = $this->createFormWithFileFieldViaApi('File Upload Cleanup Test');
        $this->createDoiConfigWithCleanup($form);

        // Get the file field from the form
        $fileField = null;
        foreach ($form->getFields() as $field) {
            if ($field->isFileType()) {
                $fileField = $field;
                break;
            }
        }
        $this->assertInstanceOf(Field::class, $fileField, 'Form should have a file field');

        // Create an uploaded file for the submission
        $uploadedFileName = 'test-upload-'.uniqid().'.txt';
        $uploadedFilePath = $this->createUploadedFileForField($fileField, $uploadedFileName);

        // Verify the file exists before cleanup
        $this->assertFileExists($uploadedFilePath, 'Uploaded file should exist before cleanup');

        // Create a timed-out submission with the file reference in results
        $timedOutSubmission = $this->createTimedOutDoiSubmissionWithFile(
            $form,
            'fileupload@example.com',
            new \DateTime('-30 days'),
            new \DateTime('-1 day'),
            $fileField->getAlias(),
            $uploadedFileName
        );
        $doiSubmissionId  = $timedOutSubmission->getId();
        $coreSubmissionId = $timedOutSubmission->getFormSubmission()->getId();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions');
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Deleted: 1 submission', $output);

        // Verify DOI submission is deleted
        $this->em->clear();
        $deletedDoiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissionId);
        $this->assertNotInstanceOf(FormDoiSubmission::class, $deletedDoiSubmission, 'DOI submission should be deleted');

        // Verify core submission is deleted
        $deletedCoreSubmission = $this->em->getRepository(Submission::class)->find($coreSubmissionId);
        $this->assertNotInstanceOf(Submission::class, $deletedCoreSubmission, 'Core submission should be deleted');

        // Verify the uploaded file is deleted
        $this->assertFileDoesNotExist($uploadedFilePath, 'Uploaded file should be deleted with the submission');
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    public function testInvalidBatchSizeReturnsError(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--batch' => '0']);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Batch size must be a positive integer', $output);
    }

    public function testInvalidLimitReturnsError(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:doi:cleanup-submissions', ['--limit' => '-1']);
        $output        = $commandTester->getDisplay();

        $this->assertStringContainsString('Limit must be a positive integer', $output);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createDoiConfigWithCleanup(Form $form): FormDoiConfig
    {
        $config = new FormDoiConfig();
        $config->setForm($form);
        $config->setEnabled(true);
        $config->setDeleteAfterTimeout(true);
        $config->setSuccessRedirectUrl('https://example.com/success');
        $config->setErrorRedirectUrl('https://example.com/error');
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    private function createDoiConfigWithoutCleanup(Form $form): FormDoiConfig
    {
        $config = new FormDoiConfig();
        $config->setForm($form);
        $config->setEnabled(true);
        $config->setDeleteAfterTimeout(false); // Cleanup NOT enabled
        $config->setSuccessRedirectUrl('https://example.com/success');
        $config->setErrorRedirectUrl('https://example.com/error');
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    /**
     * Create a DOI submission for an EXISTING contact (contact created before submission).
     * This simulates a contact that was already known to the system.
     */
    private function createDoiSubmissionForExistingContact(
        Form $form,
        Lead $existingContact,
        \DateTime $dateSubmitted,
    ): FormDoiSubmission {
        $submission = new Submission();
        $submission->setForm($form);
        $submission->setDateSubmitted($dateSubmitted);
        $submission->setReferer('https://example.com/');
        $submission->setLead($existingContact);
        $this->em->persist($submission);

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission->setForm($form);
        $doiSubmission->setFormSubmission($submission);
        $doiSubmission->setLead($existingContact);
        $doiSubmission->setEmail($existingContact->getEmail());
        $doiSubmission->setStatus(FormDoiSubmission::STATUS_PENDING);
        $doiSubmission->setHash(hash('sha256', $existingContact->getEmail().time().random_int(0, 100000)));
        $doiSubmission->setDateCreated($dateSubmitted);
        $this->em->persist($doiSubmission);
        $this->em->flush();

        return $doiSubmission;
    }

    /**
     * Create a timed-out DOI submission.
     */
    private function createTimedOutDoiSubmission(
        Form $form,
        string $email,
        \DateTime $dateCreated,
        \DateTime $dateTimeout,
    ): FormDoiSubmission {
        $doiSubmission = $this->formFixtureHelper->createDoiSubmission($form, $email, $dateCreated);
        $doiSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $doiSubmission->setDateTimeout($dateTimeout);
        $this->em->persist($doiSubmission);
        $this->em->flush();

        return $doiSubmission;
    }

    /**
     * Create a timed-out DOI submission for a NEW contact (contact created at same time as submission).
     * This simulates a contact that was created by the form submission and then timed out.
     */
    private function createTimedOutDoiSubmissionWithNewContact(
        Form $form,
        string $email,
        \DateTime $dateCreated,
        \DateTime $dateTimeout,
    ): FormDoiSubmission {
        // Create contact with same dateAdded as submission dateCreated
        $contact = new Lead();
        $contact->setEmail($email);
        $contact->setDateAdded($dateCreated);
        $contact->setDateIdentified($dateCreated);
        $contact->setLastActive($dateCreated);
        $this->em->persist($contact);

        $submission = new Submission();
        $submission->setForm($form);
        $submission->setDateSubmitted($dateCreated);
        $submission->setReferer('https://example.com/');
        $submission->setLead($contact);
        $this->em->persist($submission);

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission->setForm($form);
        $doiSubmission->setFormSubmission($submission);
        $doiSubmission->setLead($contact);
        $doiSubmission->setEmail($email);
        $doiSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $doiSubmission->setHash(hash('sha256', $email.time().random_int(0, 100000)));
        $doiSubmission->setDateCreated($dateCreated);
        $doiSubmission->setDateTimeout($dateTimeout);
        $this->em->persist($doiSubmission);
        $this->em->flush();

        return $doiSubmission;
    }

    /**
     * Create a timed-out DOI submission for an EXISTING contact.
     * This simulates a contact that was already known and whose submission timed out.
     */
    private function createTimedOutDoiSubmissionForExistingContact(
        Form $form,
        Lead $existingContact,
        \DateTime $dateCreated,
        \DateTime $dateTimeout,
    ): FormDoiSubmission {
        $submission = new Submission();
        $submission->setForm($form);
        $submission->setDateSubmitted($dateCreated);
        $submission->setReferer('https://example.com/');
        $submission->setLead($existingContact);
        $this->em->persist($submission);

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission->setForm($form);
        $doiSubmission->setFormSubmission($submission);
        $doiSubmission->setLead($existingContact);
        $doiSubmission->setEmail($existingContact->getEmail());
        $doiSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $doiSubmission->setHash(hash('sha256', $existingContact->getEmail().time().random_int(0, 100000)));
        $doiSubmission->setDateCreated($dateCreated);
        $doiSubmission->setDateTimeout($dateTimeout);
        $this->em->persist($doiSubmission);
        $this->em->flush();

        return $doiSubmission;
    }

    private function createFormWithFileFieldViaApi(string $name): Form
    {
        $formPayload = [
            'name'        => $name,
            'description' => '',
            'formType'    => 'standalone',
            'isPublished' => true,
            'fields'      => [
                [
                    'label'        => 'Email',
                    'type'         => 'email',
                    'alias'        => 'email',
                    'leadField'    => 'email',
                    'mappedField'  => 'email',
                    'mappedObject' => 'contact',
                ],
                [
                    'label'      => 'Attachment',
                    'alias'      => 'attachment',
                    'type'       => 'file',
                    'properties' => [
                        'allowed_file_size'       => 1,
                        'allowed_file_extensions' => ['txt', 'pdf', 'png'],
                    ],
                ],
                [
                    'label' => 'Submit',
                    'type'  => 'button',
                ],
            ],
            'postAction' => 'return',
        ];

        $this->client->request('POST', '/api/forms/new', $formPayload);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $formId   = $response['form']['id'];

        return $this->em->getRepository(Form::class)->find($formId);
    }

    /**
     * Create a physical file in the form upload directory for a given field.
     *
     * @return string The full path to the created file
     */
    private function createUploadedFileForField(Field $field, string $fileName): string
    {
        /** @var CoreParametersHelper $coreParametersHelper */
        $coreParametersHelper = self::getContainer()->get(CoreParametersHelper::class);
        $uploadDir            = $coreParametersHelper->get('form_upload_dir');
        $formId               = $field->getForm()->getId();
        $fieldId              = $field->getId();

        $fieldUploadDir = $uploadDir.DIRECTORY_SEPARATOR.$formId.DIRECTORY_SEPARATOR.$fieldId;
        if (!is_dir($fieldUploadDir)) {
            mkdir($fieldUploadDir, 0755, true);
        }

        $filePath = $fieldUploadDir.DIRECTORY_SEPARATOR.$fileName;
        file_put_contents($filePath, 'Test file content for cleanup test');

        return $filePath;
    }

    /**
     * Create a timed-out DOI submission that has a file uploaded.
     */
    private function createTimedOutDoiSubmissionWithFile(
        Form $form,
        string $email,
        \DateTime $dateCreated,
        \DateTime $dateTimeout,
        string $fileFieldAlias,
        string $fileName,
    ): FormDoiSubmission {
        $contact = new Lead();
        $contact->setEmail($email);
        $contact->setDateAdded($dateCreated);
        $contact->setDateIdentified($dateCreated);
        $contact->setLastActive($dateCreated);
        $this->em->persist($contact);

        $submission = new Submission();
        $submission->setForm($form);
        $submission->setDateSubmitted($dateCreated);
        $submission->setReferer('https://example.com/');
        $submission->setLead($contact);
        // Set the file reference in submission results
        $submission->setResults([$fileFieldAlias => $fileName]);
        $this->em->persist($submission);

        $doiSubmission = new FormDoiSubmission();
        $doiSubmission->setForm($form);
        $doiSubmission->setFormSubmission($submission);
        $doiSubmission->setLead($contact);
        $doiSubmission->setEmail($email);
        $doiSubmission->setStatus(FormDoiSubmission::STATUS_TIMEOUT);
        $doiSubmission->setHash(hash('sha256', $email.time().random_int(0, 100000)));
        $doiSubmission->setDateCreated($dateCreated);
        $doiSubmission->setDateTimeout($dateTimeout);
        $this->em->persist($doiSubmission);
        $this->em->flush();

        return $doiSubmission;
    }
}
