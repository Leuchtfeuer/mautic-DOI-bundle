<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Service;

use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryAction;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryMetadata;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\DoiVerificationHistoryRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DoiVerificationHistoryRecorderTest extends TestCase
{
    /** @var LeadEventLogRepository&MockObject */
    private MockObject $repository;

    /** @var LoggerInterface&MockObject */
    private MockObject $logger;

    private DoiVerificationHistoryRecorder $recorder;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LeadEventLogRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->recorder   = new DoiVerificationHistoryRecorder($this->repository, $this->logger);
    }

    public function testRecordSuccessPersistsLogWithCorrectAction(): void
    {
        $submission = $this->makeSubmission(id: 42, formName: 'Test Form');

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log): void {
                $this->assertSame(DoiVerificationHistoryMetadata::BUNDLE, $log->getBundle());
                $this->assertSame(DoiVerificationHistoryMetadata::OBJECT, $log->getObject());
                $this->assertSame('success', $log->getAction());
                $this->assertSame(42, $log->getObjectId());
            });

        $this->repository->expects($this->once())->method('detachEntity');

        $this->recorder->recordSuccess($submission);
    }

    public function testRecordFailurePersistsLogWithCorrectAction(): void
    {
        $submission = $this->makeSubmission(id: 7, formName: 'Another Form');

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log): void {
                $this->assertSame('failure', $log->getAction());
            });

        $this->repository->expects($this->once())->method('detachEntity');

        $this->recorder->recordFailure($submission);
    }

    public function testRecordSkippedPersistsLogWithCorrectAction(): void
    {
        $skippedAt  = new \DateTime('2026-03-10 14:00:00');
        $submission = $this->makeSubmission(id: 11, formName: 'Skip Form', dateCreated: $skippedAt);

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log) use ($skippedAt): void {
                $this->assertSame('skipped', $log->getAction());
                $this->assertEquals($skippedAt, $log->getDateAdded());
            });

        $this->repository->expects($this->once())->method('detachEntity');

        $this->recorder->recordSkipped($submission);
    }

    public function testRecordSkippedUsesExplicitSkippedAtOverDateCreated(): void
    {
        $dateCreated = new \DateTime('2026-03-10 14:00:00');
        $skippedAt   = new \DateTime('2026-03-10 15:00:00');
        $submission  = $this->makeSubmission(id: 11, formName: 'Skip Form', dateCreated: $dateCreated);

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log) use ($skippedAt): void {
                $this->assertEquals($skippedAt, $log->getDateAdded());
            });

        $this->repository->method('detachEntity');

        $this->recorder->recordSkipped($submission, $skippedAt);
    }

    public function testDeduplicationPreventsSecondSkippedEntry(): void
    {
        $submission = $this->makeSubmission(id: 5, formName: 'Form', dateCreated: new \DateTime());

        $this->repository->method('getSpecificRows')
            ->with(5, DoiVerificationHistoryAction::SKIPPED->value, $this->anything(), DoiVerificationHistoryMetadata::BUNDLE, DoiVerificationHistoryMetadata::OBJECT)
            ->willReturn([['id' => 99]]);

        $this->repository->expects($this->never())->method('saveEntity');

        $this->recorder->recordSkipped($submission);
    }

    public function testRecordSuccessUsesDateConfirmedAsTimestamp(): void
    {
        $confirmedAt = new \DateTime('2026-01-15 10:30:00');
        $submission  = $this->makeSubmission(id: 1, formName: 'Form', dateConfirmed: $confirmedAt);

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log) use ($confirmedAt): void {
                $this->assertEquals($confirmedAt, $log->getDateAdded());
            });

        $this->repository->method('detachEntity');

        $this->recorder->recordSuccess($submission);
    }

    public function testRecordSuccessUsesExplicitClickedAtOverDateConfirmed(): void
    {
        $confirmedAt = new \DateTime('2026-01-15 10:30:00');
        $clickedAt   = new \DateTime('2026-01-15 11:00:00');
        $submission  = $this->makeSubmission(id: 1, formName: 'Form', dateConfirmed: $confirmedAt);

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log) use ($clickedAt): void {
                $this->assertEquals($clickedAt, $log->getDateAdded());
            });

        $this->repository->method('detachEntity');

        $this->recorder->recordSuccess($submission, $clickedAt);
    }

    public function testDeduplicationPreventsSecondSuccessEntry(): void
    {
        $submission = $this->makeSubmission(id: 5, formName: 'Form');

        $this->repository->method('getSpecificRows')
            ->with(5, DoiVerificationHistoryAction::SUCCESS->value, $this->anything(), DoiVerificationHistoryMetadata::BUNDLE, DoiVerificationHistoryMetadata::OBJECT)
            ->willReturn([['id' => 99]]);

        $this->repository->expects($this->never())->method('saveEntity');

        $this->recorder->recordSuccess($submission);
    }

    public function testDeduplicationPreventsSecondFailureEntry(): void
    {
        $submission = $this->makeSubmission(id: 5, formName: 'Form');

        $this->repository->method('getSpecificRows')
            ->with(5, DoiVerificationHistoryAction::FAILURE->value, $this->anything(), DoiVerificationHistoryMetadata::BUNDLE, DoiVerificationHistoryMetadata::OBJECT)
            ->willReturn([['id' => 99]]);

        $this->repository->expects($this->never())->method('saveEntity');

        $this->recorder->recordFailure($submission);
    }

    public function testDoesNotRecordWhenLeadIsNull(): void
    {
        $submission = $this->makeSubmission(id: 1, formName: 'Form', withLead: false);

        $this->repository->expects($this->never())->method('saveEntity');

        $this->recorder->recordSuccess($submission);
    }

    public function testDoesNotRecordWhenSubmissionIdIsNull(): void
    {
        $submission = $this->makeSubmission(id: null, formName: 'Form');

        $this->repository->expects($this->never())->method('saveEntity');

        $this->recorder->recordSuccess($submission);
    }

    public function testRecordSuccessSwallowsRepositoryException(): void
    {
        $submission = $this->makeSubmission(id: 1, formName: 'Form');

        $this->repository->method('getSpecificRows')->willReturn([]);
        $this->repository->method('saveEntity')->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to record DOI verification history',
                $this->callback(function (array $context): bool {
                    $this->assertSame(1, $context['doiSubmissionId']);
                    $this->assertSame('success', $context['action']);
                    $this->assertSame('DB error', $context['error']);

                    return true;
                })
            );

        $this->recorder->recordSuccess($submission);
    }

    public function testPropertiesContainFormDetails(): void
    {
        $form = $this->createMock(Form::class);
        $form->method('getName')->willReturn('My DOI Form');
        $form->method('getId')->willReturn(99);

        $submission = $this->makeSubmission(id: 3, formName: 'My DOI Form', form: $form);

        $this->repository->method('getSpecificRows')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('saveEntity')
            ->willReturnCallback(function (LeadEventLog $log): void {
                $props = $log->getProperties();
                $this->assertSame('My DOI Form', $props['object_description']);
                $this->assertSame(99, $props['form_id']);
                $this->assertSame(3, $props['doi_submission_id']);
            });

        $this->repository->method('detachEntity');

        $this->recorder->recordSuccess($submission);
    }

    private function makeSubmission(
        ?int $id,
        string $formName,
        bool $withLead = true,
        ?\DateTimeInterface $dateConfirmed = null,
        ?Form $form = null,
        ?\DateTimeInterface $dateCreated = null,
    ): FormDoiSubmission {
        if (null === $form) {
            $form = $this->createMock(Form::class);
            $form->method('getName')->willReturn($formName);
            $form->method('getId')->willReturn(1);
        }

        $lead = $withLead ? $this->createMock(Lead::class) : null;

        $submission = $this->createMock(FormDoiSubmission::class);
        $submission->method('getId')->willReturn($id);
        $submission->method('getLead')->willReturn($lead);
        $submission->method('getForm')->willReturn($form);
        $submission->method('getDateConfirmed')->willReturn($dateConfirmed);
        $submission->method('getDateCreated')->willReturn(
            $dateCreated instanceof \DateTime
                ? $dateCreated
                : new \DateTime($dateCreated?->format('Y-m-d H:i:s') ?? 'now')
        );

        return $submission;
    }
}
