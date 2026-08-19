<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Service;

use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\ConsentSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class ConsentSnapshotBuilderTest extends TestCase
{
    public function testBuildUsesFormStructureAndIncludesOnlySelectedOptions(): void
    {
        $form = $this->createFormWithCheckbox();

        $snapshot = (new ConsentSnapshotBuilder())->build($form, ['consent' => ['yes']]);

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

        $snapshot = (new ConsentSnapshotBuilder())->build($form, ['consent' => 'unknown']);

        self::assertSame('unknown', $snapshot['fields'][0]['selected_options'][0]['value']);
        self::assertNull($snapshot['fields'][0]['selected_options'][0]['label']);
    }

    public function testBuildIgnoresInvalidValuesAndPreservesZero(): void
    {
        $form = $this->createFormWithCheckbox();

        $snapshot = (new ConsentSnapshotBuilder())->build($form, [
            'consent' => ['', null, [], '0', 'yes', 'yes'],
        ]);

        self::assertSame([
            ['value' => '0', 'label' => 'consent.zero.token'],
            ['value' => 'yes', 'label' => 'consent.yes.token'],
        ], $snapshot['fields'][0]['selected_options']);
    }

    public function testBuildReturnsEmptySnapshotWithoutSelectedCheckboxes(): void
    {
        $form       = $this->createFormWithCheckbox();
        $snapshot   = (new ConsentSnapshotBuilder())->build($form, ['email' => 'contact@example.com']);

        self::assertSame([
            'version' => 1,
            'fields'  => [],
        ], $snapshot);
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
