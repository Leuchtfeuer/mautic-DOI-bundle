<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\LastDoiDateField;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\LastDoiDateFieldInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LastDoiDateFieldInstallerTest extends TestCase
{
    /** @var FieldModel&MockObject */
    private MockObject $fieldModel;

    /** @var TranslatorInterface&MockObject */
    private MockObject $translator;

    /** @var LoggerInterface&MockObject */
    private MockObject $logger;

    private LastDoiDateFieldInstaller $installer;

    protected function setUp(): void
    {
        $this->fieldModel  = $this->createMock(FieldModel::class);
        $this->translator  = $this->createMock(TranslatorInterface::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->installer   = new LastDoiDateFieldInstaller($this->fieldModel, $this->translator, $this->logger);
    }

    public function testInstallIfMissingSkipsWhenFieldAlreadyExists(): void
    {
        $this->fieldModel->expects($this->once())
            ->method('getEntityByAlias')
            ->with(LastDoiDateField::ALIAS)
            ->willReturn(new LeadField());

        $this->fieldModel->expects($this->never())->method('saveEntity');
        $this->translator->expects($this->never())->method('trans');

        $this->installer->installIfMissing();
    }

    public function testInstallIfMissingCreatesDatetimeField(): void
    {
        $this->fieldModel->expects($this->once())
            ->method('getEntityByAlias')
            ->with(LastDoiDateField::ALIAS)
            ->willReturn(null);

        $this->translator->expects($this->once())
            ->method('trans')
            ->with(LastDoiDateField::LABEL_TRANSLATION_KEY)
            ->willReturn('Last DOI Date');

        $this->fieldModel->expects($this->once())
            ->method('saveEntity')
            ->with($this->callback(function (LeadField $field): bool {
                $this->assertSame(LastDoiDateField::ALIAS, $field->getAlias());
                $this->assertSame('datetime', $field->getType());
                $this->assertSame('lead', $field->getObject());
                $this->assertSame('Last DOI Date', $field->getLabel());
                $this->assertTrue($field->getIsListable());

                return true;
            }));

        $this->installer->installIfMissing();
    }

    public function testInstallIfMissingLogsErrorWhenSaveFails(): void
    {
        $this->fieldModel->method('getEntityByAlias')->willReturn(null);
        $this->translator->method('trans')->willReturn('Last DOI Date');
        $this->fieldModel->method('saveEntity')->willThrowException(new \RuntimeException('save failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to install last_doi_date contact field',
                $this->callback(function (array $context): bool {
                    $this->assertSame('save failed', $context['error']);

                    return true;
                })
            );

        $this->installer->installIfMissing();
    }
}
