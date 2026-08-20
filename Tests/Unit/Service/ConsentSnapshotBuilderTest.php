<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Service;

use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Event\ResolveConsentSnapshotEvent;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\ConsentSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class ConsentSnapshotBuilderTest extends TestCase
{
    public function testBuildUsesFormStructureAndIncludesOnlySelectedOptions(): void
    {
        $form = $this->createFormWithCheckbox();

        $snapshot = $this->createBuilder()->build($form, ['consent' => ['yes']], new Request());

        self::assertSame([
            'version' => 1,
            'fields'  => [[
                'alias'            => 'consent',
                'label'            => 'consent.group.token',
                'selected_options' => [[
                    'value' => 'yes',
                    'label' => 'consent.yes.token',
                ]],
            ]],
        ], $snapshot);
    }

    public function testBuildStoresNullLabelForUnknownSelectedOption(): void
    {
        $form = $this->createFormWithCheckbox();

        $snapshot = $this->createBuilder()->build($form, ['consent' => 'unknown'], new Request());

        self::assertSame('unknown', $snapshot['fields'][0]['selected_options'][0]['value']);
        self::assertNull($snapshot['fields'][0]['selected_options'][0]['label']);
    }

    public function testBuildIgnoresInvalidValuesAndPreservesZero(): void
    {
        $form = $this->createFormWithCheckbox();

        $snapshot = $this->createBuilder()->build($form, [
            'consent' => ['', null, [], '0', 'yes', 'yes'],
        ], new Request());

        self::assertSame([
            ['value' => '0', 'label' => 'consent.zero.token'],
            ['value' => 'yes', 'label' => 'consent.yes.token'],
        ], $snapshot['fields'][0]['selected_options']);
    }

    public function testBuildReturnsEmptySnapshotWithoutSelectedCheckboxes(): void
    {
        $form       = $this->createFormWithCheckbox();
        $snapshot   = $this->createBuilder()->build($form, ['email' => 'contact@example.com'], new Request());

        self::assertSame([
            'version' => 1,
            'fields'  => [],
        ], $snapshot);
    }

    public function testBuildDispatchesLabelResolutionWithoutExposingSnapshotStructure(): void
    {
        $form       = $this->createFormWithCheckbox();
        $request    = new Request();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (ResolveConsentSnapshotEvent $event) use ($form, $request): bool {
                self::assertSame($form, $event->getForm());
                self::assertSame($request, $event->getRequest());
                $event->transformLabels(static fn (string $label): string => 'resolved '.$label);

                return true;
            }), DoiEvents::DOI_ON_RESOLVE_CONSENT_SNAPSHOT)
            ->willReturnArgument(0);

        $snapshot = (new ConsentSnapshotBuilder($dispatcher, $this->createStub(LoggerInterface::class)))
            ->build($form, ['consent' => ['yes']], $request);

        self::assertSame(1, $snapshot['version']);
        self::assertSame('consent', $snapshot['fields'][0]['alias']);
        self::assertSame('resolved consent.group.token', $snapshot['fields'][0]['label']);
        self::assertSame('yes', $snapshot['fields'][0]['selected_options'][0]['value']);
        self::assertSame('resolved consent.yes.token', $snapshot['fields'][0]['selected_options'][0]['label']);
    }

    public function testBuildReturnsEntireSourceSnapshotWhenResolverFails(): void
    {
        $form       = $this->createFormWithCheckbox();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (ResolveConsentSnapshotEvent $event): never {
                $event->transformLabels(static fn (string $label): string => 'partially resolved '.$label);

                throw new \RuntimeException('Resolution failed');
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('DOI consent snapshot label resolution failed', self::callback(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
            ));

        $snapshot = (new ConsentSnapshotBuilder($dispatcher, $logger))
            ->build($form, ['consent' => ['yes']], new Request());

        self::assertSame('consent.group.token', $snapshot['fields'][0]['label']);
        self::assertSame('consent.yes.token', $snapshot['fields'][0]['selected_options'][0]['label']);
    }

    private function createBuilder(): ConsentSnapshotBuilder
    {
        return new ConsentSnapshotBuilder(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function createFormWithCheckbox(): Form
    {
        $form  = new Form();
        $field = new Field();
        $field->setForm($form);
        $field->setType('checkboxgrp');
        $field->setAlias('consent');
        $field->setLabel('consent.group.token');
        $field->setProperties([
            'optionlist' => [
                'list' => [
                    ['label' => 'consent.yes.token', 'value' => 'yes'],
                    ['label' => 'consent.no.token', 'value' => 'no'],
                    ['label' => 'consent.zero.token', 'value' => '0'],
                ],
            ],
        ]);
        $form->addField(1, $field);

        return $form;
    }
}
