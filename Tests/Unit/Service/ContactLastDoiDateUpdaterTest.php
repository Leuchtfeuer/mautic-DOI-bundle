<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Service;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\LastDoiDateField;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\ContactLastDoiDateUpdater;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContactLastDoiDateUpdaterTest extends TestCase
{
    /** @var LeadModel&MockObject */
    private MockObject $leadModel;

    /** @var LoggerInterface&MockObject */
    private MockObject $logger;

    private ContactLastDoiDateUpdater $updater;

    protected function setUp(): void
    {
        $this->leadModel = $this->createMock(LeadModel::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->updater   = new ContactLastDoiDateUpdater($this->leadModel, $this->logger);
    }

    public function testUpdateFromSubmissionDoesNothingWhenNotConfirmed(): void
    {
        $submission = $this->createSubmission(FormDoiSubmission::STATUS_PENDING, null);

        $this->leadModel->expects($this->never())->method('setFieldValues');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $this->updater->updateFromSubmission($submission);
    }

    public function testUpdateFromSubmissionDoesNothingWhenLeadMissing(): void
    {
        $submission = $this->createSubmission(FormDoiSubmission::STATUS_CONFIRMED, new \DateTime('2026-06-16 12:00:00'));
        $submission->setLead(null);

        $this->leadModel->expects($this->never())->method('setFieldValues');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $this->updater->updateFromSubmission($submission);
    }

    public function testUpdateFromSubmissionSetsLastDoiDateOnConfirmedSubmission(): void
    {
        $confirmedAt = new \DateTime('2026-06-16 12:34:56');
        $lead        = new Lead();
        $lead->setId(42);
        $submission = $this->createSubmission(FormDoiSubmission::STATUS_CONFIRMED, $confirmedAt);
        $submission->setLead($lead);

        $this->leadModel->expects($this->once())
            ->method('setFieldValues')
            ->with(
                $lead,
                [LastDoiDateField::ALIAS => '2026-06-16 12:34:56'],
                false,
                false
            );

        $this->leadModel->expects($this->once())
            ->method('saveEntity')
            ->with($lead, false);

        $this->updater->updateFromSubmission($submission);
    }

    public function testUpdateFromSubmissionLogsErrorWhenSaveFails(): void
    {
        $lead = new Lead();
        $lead->setId(7);
        $submission = $this->createSubmission(FormDoiSubmission::STATUS_CONFIRMED, new \DateTime('2026-06-16 12:00:00'));
        $submission->setLead($lead);

        $reflection = new \ReflectionProperty(FormDoiSubmission::class, 'id');
        $reflection->setValue($submission, 99);

        $this->leadModel->method('setFieldValues')->willThrowException(new \RuntimeException('update failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to update last_doi_date contact field',
                $this->callback(function (array $context): bool {
                    $this->assertSame(7, $context['leadId']);
                    $this->assertSame(99, $context['doiSubmissionId']);
                    $this->assertSame('update failed', $context['error']);

                    return true;
                })
            );

        $this->updater->updateFromSubmission($submission);
    }

    private function createSubmission(string $status, ?\DateTime $dateConfirmed): FormDoiSubmission
    {
        $form = new Form();

        $formSubmission = new Submission();
        $formSubmission->setForm($form);

        $submission = new FormDoiSubmission();
        $submission->setFormSubmission($formSubmission);
        $submission->setForm($form);
        $submission->setEmail('lead@example.com');
        $submission->setHash('hash');
        $submission->setDateCreated(new \DateTime());
        $submission->setStatus($status);
        $submission->setDateConfirmed($dateConfirmed);

        return $submission;
    }
}
