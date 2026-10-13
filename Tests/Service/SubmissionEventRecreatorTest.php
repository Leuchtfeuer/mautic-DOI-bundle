<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Service;

use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\SubmissionEventRecreator;
use PHPUnit\Framework\TestCase;

final class SubmissionEventRecreatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $results
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideContactFieldMatchesData')]
    public function testGetContactFieldMatches(string $message, Form $form, array $results, array $expected): void
    {
        $service = new SubmissionEventRecreator();
        $actual  = $service->getContactFieldMatches($form, $results);

        $this->assertSame($expected, $actual, $message);
    }

    /**
     * Data provider for testGetContactFieldMatches.
     *
     * @return \Generator<string, array{0: string, 1: Form, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    public static function provideContactFieldMatchesData(): \Generator
    {
        yield 'Simple text field' => [
            'A standard text field should return its string value.',
            self::createForm([
                self::createField(
                    alias: 'email',
                    type: 'text',
                    mappedObject: 'contact',
                    mappedField: 'email',
                    properties: []
                ),
            ]),
            ['email' => 'test@example.com'],
            ['email' => 'test@example.com'],
        ];

        yield 'Unmapped field' => [
            'A field with no mapping should be ignored.',
            self::createForm([
                self::createField(
                    alias: 'unmapped',
                    type: 'text',
                    mappedObject: null,
                    mappedField: null,
                    properties: []
                ),
            ]),
            ['unmapped' => 'some value'],
            [],
        ];

        $simpleMultiSelectProps = [
            'multiple' => 1,
            'list'     => [
                'list' => [
                    ['label' => 'Option 1', 'value' => 'opt1'],
                    ['label' => 'Option 2', 'value' => 'opt2'],
                    ['label' => 'Option 3', 'value' => 'opt3'],
                ],
            ],
        ];
        yield 'Simple multi-select' => [
            'A simple multi-select should be converted to an array.',
            self::createForm([
                self::createField(
                    alias: 'multi',
                    type: 'select',
                    mappedObject: 'contact',
                    mappedField: 'tags',
                    properties: $simpleMultiSelectProps
                ),
            ]),
            ['multi' => 'opt1, opt3'],
            ['tags'  => ['opt1', 'opt3']],
        ];

        $checkboxGrpProps = [
            'optionlist' => [
                'list' => [
                    ['label' => 'Cars, Planes', 'value' => 'Cars, Planes'],
                    ['label' => 'Water, Fire', 'value' => 'Water, Fire'],
                    ['label' => 'Dogs', 'value' => 'Dogs'],
                ],
            ],
        ];
        yield 'Checkbox group with commas in values' => [
            'A checkbox group with commas in values should be parsed correctly, respecting the full value.',
            self::createForm([
                self::createField(
                    alias: 'interests',
                    type: 'checkboxgrp',
                    mappedObject: 'contact',
                    mappedField: 'interests',
                    properties: $checkboxGrpProps
                ),
            ]),
            ['interests' => 'Dogs, Water, Fire'],
            ['interests' => ['Water, Fire', 'Dogs']],
        ];

        yield 'Checkbox group with all values' => [
            'A checkbox group with values containing commas should handle all choices.',
            self::createForm([
                self::createField(
                    alias: 'interests',
                    type: 'checkboxgrp',
                    mappedObject: 'contact',
                    mappedField: 'interests',
                    properties: $checkboxGrpProps
                ),
            ]),
            ['interests' => 'Cars, Planes, Dogs, Water, Fire'],
            ['interests' => ['Cars, Planes', 'Water, Fire', 'Dogs']],
        ];

        yield 'Multi-select with an invalid value' => [
            'An invalid option in a multi-select string should be ignored.',
            self::createForm([
                self::createField(
                    alias: 'interests',
                    type: 'checkboxgrp',
                    mappedObject: 'contact',
                    mappedField: 'interests',
                    properties: $checkboxGrpProps
                ),
            ]),
            ['interests' => 'Dogs, Bicycles, Water, Fire'],
            ['interests' => ['Water, Fire', 'Dogs']],
        ];

        yield 'Multi-select with empty string value' => [
            'An empty string from a multi-select should remain an empty string.',
            self::createForm([
                self::createField(
                    alias: 'multi',
                    type: 'select',
                    mappedObject: 'contact',
                    mappedField: 'tags',
                    properties: $simpleMultiSelectProps
                ),
            ]),
            ['multi' => ''],
            ['tags'  => ''],
        ];

        yield 'Field not in results' => [
            'A mapped field not present in results should result in an empty string value.',
            self::createForm([
                self::createField(
                    alias: 'email',
                    type: 'text',
                    mappedObject: 'contact',
                    mappedField: 'email',
                    properties: []
                ),
            ]),
            ['another_field' => 'some value'],
            ['email'         => ''],
        ];
    }

    /**
     * Helper to create a Form entity and attach fields.
     *
     * @param Field[] $fields
     */
    private static function createForm(array $fields): Form
    {
        $form = new Form();
        foreach ($fields as $key => $field) {
            $form->addField((string) $key, $field);
        }

        return $form;
    }

    /**
     * Helper to create a Field entity.
     *
     * @param array<string, mixed> $properties
     */
    private static function createField(string $alias, string $type, ?string $mappedObject, ?string $mappedField, array $properties): Field
    {
        $field = new Field();
        $field->setAlias($alias);
        $field->setType($type);
        $field->setMappedObject($mappedObject);
        $field->setMappedField($mappedField);
        $field->setProperties($properties);

        return $field;
    }
}
