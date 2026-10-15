<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Helper;

use MauticPlugin\LeuchtfeuerDoiBundle\Helper\ConditionFilterHelper;
use PHPUnit\Framework\TestCase;

final class ConditionFilterHelperTest extends TestCase
{
    /**
     * @param array<string>                         $fieldAliases
     * @param array<int, array<string, mixed>>|null $conditions
     * @param array<int, array<string, mixed>>|null $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFilterByExistingFormFieldsData')]
    public function testFilterByExistingFormFields(string $message, array $fieldAliases, ?array $conditions, ?array $expected): void
    {
        $result = ConditionFilterHelper::filterByExistingFormFields($fieldAliases, $conditions);

        $this->assertSame($expected, $result, $message);
    }

    /**
     * @return \Generator<string, array{0: string, 1: array<string>, 2: array<int, array<string, mixed>>|null, 3: array<int, array<string, mixed>>|null}>
     */
    public static function provideFilterByExistingFormFieldsData(): \Generator
    {
        yield 'null conditions returns null' => [
            'Null conditions should return null.',
            ['email', 'name'],
            null,
            null,
        ];

        yield 'empty conditions returns null' => [
            'Empty conditions array should return null.',
            ['email', 'name'],
            [],
            null,
        ];

        yield 'keeps non-form conditions' => [
            'Conditions with object other than "form" should be kept.',
            ['email'],
            [
                ['object' => 'lead', 'field' => 'firstname', 'value' => 'John'],
                ['object' => 'company', 'field' => 'companyname', 'value' => 'Acme'],
            ],
            [
                ['object' => 'lead', 'field' => 'firstname', 'value' => 'John'],
                ['object' => 'company', 'field' => 'companyname', 'value' => 'Acme'],
            ],
        ];

        yield 'keeps form conditions with existing fields' => [
            'Form conditions referencing existing fields should be kept.',
            ['email', 'name', 'phone'],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
                ['object' => 'form', 'field' => 'name', 'value' => 'John'],
            ],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
                ['object' => 'form', 'field' => 'name', 'value' => 'John'],
            ],
        ];

        yield 'removes form conditions with deleted fields' => [
            'Form conditions referencing deleted fields should be removed.',
            ['email'],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
                ['object' => 'form', 'field' => 'deleted_field', 'value' => 'some value'],
            ],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
            ],
        ];

        yield 'mixed conditions filtered correctly' => [
            'Mixed form and non-form conditions should be filtered correctly.',
            ['email'],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
                ['object' => 'form', 'field' => 'deleted_field', 'value' => 'removed'],
                ['object' => 'lead', 'field' => 'lastname', 'value' => 'Doe'],
            ],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'test@example.com'],
                ['object' => 'lead', 'field' => 'lastname', 'value' => 'Doe'],
            ],
        ];

        yield 'all form conditions removed returns null' => [
            'When all form conditions reference deleted fields, should return null.',
            ['email'],
            [
                ['object' => 'form', 'field' => 'deleted_field1', 'value' => 'value1'],
                ['object' => 'form', 'field' => 'deleted_field2', 'value' => 'value2'],
            ],
            null,
        ];

        yield 'condition without object key kept' => [
            'Conditions without object key should be kept (not form-based).',
            ['email'],
            [
                ['field' => 'something', 'value' => 'test'],
            ],
            [
                ['field' => 'something', 'value' => 'test'],
            ],
        ];

        yield 'condition without field key removed for form object' => [
            'Form conditions without field key should be removed (empty string not in aliases).',
            ['email', 'name'],
            [
                ['object' => 'form', 'value' => 'test'],
            ],
            null,
        ];

        yield 'reindexes array after filtering' => [
            'Filtered array should be re-indexed with array_values.',
            ['name'],
            [
                ['object' => 'form', 'field' => 'email', 'value' => 'removed'],
                ['object' => 'form', 'field' => 'name', 'value' => 'kept'],
            ],
            [
                ['object' => 'form', 'field' => 'name', 'value' => 'kept'],
            ],
        ];
    }
}
