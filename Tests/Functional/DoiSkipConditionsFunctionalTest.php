<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Segment\OperatorOptions;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\DoiVerificationHistoryMetadata;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;

class DoiSkipConditionsFunctionalTest extends MauticMysqlTestCase
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
     * @param array<int, mixed>    $skipConditions
     * @param array<string, mixed> $submissionData
     * @param int                  $contactNumber  To ensure unique emails per test
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipConditionsDataProvider')]
    public function testSkipConditionsEvaluation(array $skipConditions, array $submissionData, string $expectedStatus, int $contactNumber): void
    {
        // 1. Setup: Create Form and DOI Config with the specific conditions
        $form = $this->formFixtureHelper->createComplexFormViaApi('Conditions Test Form');
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            skipConditions: $skipConditions
        );

        $email                   = "contact-{$contactNumber}@example.com";
        $submissionData['email'] = $email;

        // 2. Execution: Submit the form
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_conditionstestform]');
        $formElement = $formCrawler->form();

        $mauticFormValues = [];
        foreach ($submissionData as $key => $value) {
            if (is_array($value)) {
                // Check if this is a multiselect field first
                $multiselectFieldName = "mauticform[{$key}]";
                if ($formElement->has($multiselectFieldName)) {
                    /** @var ChoiceFormField $field */
                    $field = $formElement->get($multiselectFieldName);
                    // Check if field is an object and has setValue method
                    if (is_object($field) && method_exists($field, 'setValue')) {
                        // For multiselect fields, we need to set the array of values directly
                        $field->setValue($value);
                    } else {
                        // Fallback to checkbox handling if not a valid multiselect field
                        $this->handleCheckboxValues($formElement, $key, $value);
                    }
                } else {
                    // Handle checkbox arrays - need to find checkboxes by their value, not by array index
                    $this->handleCheckboxValues($formElement, $key, $value);
                }
            } else {
                // Handle regular form fields
                $fieldName = "mauticform[{$key}]";
                if ($formElement->has($fieldName)) {
                    $formElement->get($fieldName)->setValue($value);
                }
            }
        }

        $formElement->setValues($mauticFormValues);
        $this->client->submit($formElement);
        self::assertResponseIsSuccessful();

        // 3. Assertion: Check the status of the created DOI submission
        $doiRepo = $this->em->getRepository(FormDoiSubmission::class);
        /** @var FormDoiSubmission|null $doiSubmission */
        $doiSubmission = $doiRepo->findOneBy(['email' => $email]);

        Assert::assertNotNull($doiSubmission, 'FormDoiSubmission was not created.');
        Assert::assertSame($expectedStatus, $doiSubmission->getStatus(), 'The DOI submission status is incorrect.');
        $consentSnapshot = $doiSubmission->getSubmittedConsentSnapshot();
        Assert::assertIsArray($consentSnapshot);
        Assert::assertSame(1, $consentSnapshot['version']);

        if (FormDoiSubmission::STATUS_SKIPPED === $expectedStatus) {
            Assert::assertTrue($doiSubmission->isVerificationSkipped(), 'isVerificationSkipped flag should be true.');
            Assert::assertSame(FormDoiSubmission::SKIP_REASON_CONDITION_MATCH, $doiSubmission->getSkipReason(), 'Skip reason does not match.');
            Assert::assertNotNull($doiSubmission->getDateConfirmed(), 'DateConfirmed should be set immediately on skip.');

            $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
                'bundle'   => DoiVerificationHistoryMetadata::BUNDLE,
                'object'   => DoiVerificationHistoryMetadata::OBJECT,
                'objectId' => $doiSubmission->getId(),
                'action'   => 'skipped',
            ]);
            Assert::assertCount(1, $logs, 'Skipped verification should create a contact history entry.');
            Assert::assertEquals(
                $doiSubmission->getDateCreated()->format('Y-m-d H:i:s'),
                $logs[0]->getDateAdded()->format('Y-m-d H:i:s'),
                'History entry timestamp should match form submission time.'
            );
        } else { // PENDING
            Assert::assertFalse($doiSubmission->isVerificationSkipped(), 'isVerificationSkipped flag should be false for pending submissions.');
            Assert::assertNull($doiSubmission->getSkipReason(), 'Skip reason should be null for pending submissions.');
            Assert::assertNull($doiSubmission->getDateConfirmed(), 'DateConfirmed should be null for pending submissions.');

            $logs = $this->em->getRepository(LeadEventLog::class)->findBy([
                'bundle'   => DoiVerificationHistoryMetadata::BUNDLE,
                'object'   => DoiVerificationHistoryMetadata::OBJECT,
                'objectId' => $doiSubmission->getId(),
                'action'   => 'skipped',
            ]);
            Assert::assertCount(0, $logs, 'Pending verification should not create a skipped history entry.');
        }
    }

    /**
     * @param array<int, string> $values
     */
    private function handleCheckboxValues(Form $formElement, string $key, array $values): void
    {
        $allFields = $formElement->all();

        /**
         * @var ChoiceFormField $field
         */
        foreach ($allFields as $fieldName => $field) {
            // Match checkbox fields for this key (e.g., mauticform[interests][0], mauticform[interests][1])
            if (preg_match("/^mauticform\[{$key}\]\[\d+\]$/", $fieldName)) {
                // Check if this checkbox's value is in our desired values array
                if (is_object($field) && method_exists($field, 'availableOptionValues')) {
                    $checkboxValue = $field->availableOptionValues()[0] ?? null;
                    if ($checkboxValue && in_array($checkboxValue, $values, true)) {
                        $field->tick();
                    }
                }
            }
        }
    }

    /**
     * @return \Generator<string, array<string, mixed>>
     */
    public static function skipConditionsDataProvider(): \Generator
    {
        $contactNumber = 0;

        yield 'Lead country matches, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
            ],
            'submissionData' => ['country' => 'Poland'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Lead country does not match, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
            ],
            'submissionData' => ['country' => 'Germany'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Company name matches (AND), should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['country' => 'Poland', 'companyname' => 'Acme Inc'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Company name does not match (AND), should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['country' => 'Poland', 'companyname' => 'Globex Corp'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Company name matches (OR), should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'or', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['country' => 'Germany', 'companyname' => 'Acme Inc'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Neither condition matches (OR), should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'or', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['country' => 'Germany', 'companyname' => 'Globex Corp'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Form-only field matches, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'B2B'], 'field' => 'source', 'type' => 'text', 'object' => 'form'],
            ],
            'submissionData' => ['country' => 'Germany', 'companyname' => 'Globex Corp', 'source' => 'B2B'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Form-only field does not match, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'B2B'], 'field' => 'source', 'type' => 'text', 'object' => 'form'],
            ],
            'submissionData' => ['country' => 'Germany', 'companyname' => 'Globex Corp', 'source' => 'Website'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Complex mixed glue matches OR part, should skip' => [
            'skipConditions' => [
                // (country=Poland AND company=Acme) OR source=VIP
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
                ['glue' => 'or', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'VIP'], 'field' => 'source', 'type' => 'text', 'object' => 'form'],
            ],
            'submissionData' => ['country' => 'Germany', 'companyname' => 'Globex Corp', 'source' => 'VIP'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Complex mixed glue matches AND part, should skip' => [
            'skipConditions' => [
                // (country=Poland AND company=Acme) OR source=VIP
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Poland'], 'field' => 'country', 'type' => 'country', 'object' => 'lead'],
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'Acme Inc'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
                ['glue' => 'or', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'VIP'], 'field' => 'source', 'type' => 'text', 'object' => 'form'],
            ],
            'submissionData' => ['country' => 'Poland', 'companyname' => 'Acme Inc', 'source' => 'Other'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        // --- SELECT FIELD (COLORS) TEST CASES ---

        yield 'Colors select EQUAL_TO exact value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'red'], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'red'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select EQUAL_TO different value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'red'], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'blue'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_EQUAL_TO different value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'red'], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'green'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_EQUAL_TO same value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'red'], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'red'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select EMPTY with empty value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EMPTY, 'properties' => ['filter' => ''], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => ''],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select EMPTY with non-empty value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EMPTY, 'properties' => ['filter' => ''], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'blue'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_EMPTY with non-empty value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EMPTY, 'properties' => ['filter' => ''], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'green'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_EMPTY with empty value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EMPTY, 'properties' => ['filter' => ''], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => ''],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select IN with matching value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['red', 'green']], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'red'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select IN with non-matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['red', 'green']], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'blue'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_IN with non-matching value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['red', 'green']], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'blue'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Colors select NOT_IN with matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['red', 'green']], 'field' => 'colors', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['colors' => 'green'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        // --- MULTISELECT FIELD (AVAILABLE_DAYS) TEST CASES ---

        yield 'Available days multiselect EQUAL_TO exact value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'monday'], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect EQUAL_TO with multiple values, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'monday'], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday', 'tuesday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_EQUAL_TO different value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'monday'], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['tuesday', 'wednesday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_EQUAL_TO with matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'monday'], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect EMPTY with empty value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EMPTY, 'properties' => ['filter' => ''], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => []],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect EMPTY with non-empty value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EMPTY, 'properties' => ['filter' => ''], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['friday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_EMPTY with non-empty value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EMPTY, 'properties' => ['filter' => ''], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['saturday', 'sunday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_EMPTY with empty value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EMPTY, 'properties' => ['filter' => ''], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => []],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect IN with one matching value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['tuesday', 'wednesday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect IN with multiple matching values, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday', 'friday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday', 'wednesday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect IN with no matching values, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['tuesday', 'thursday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_IN with no matching values, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['tuesday', 'thursday']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_IN with one matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday', 'thursday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Available days multiselect NOT_IN with all matching values, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['monday', 'wednesday']], 'field' => 'available_days', 'type' => 'select', 'object' => 'form'],
            ],
            'submissionData' => ['available_days' => ['monday', 'wednesday']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        // --- CHECKBOX TEST CASES ---

        yield 'Checkbox EQUALS exact value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'sales'], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            'submissionData' => ['interests' => ['sales']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Checkbox IN rule where one submitted value matches, should skip' => [
            'skipConditions' => [
                // Skips if submitted interests include 'tech' OR 'sales'
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['tech', 'sales']], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            // user submits 'marketing' and 'tech'
            'submissionData' => ['interests' => ['marketing', 'tech']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Checkbox IN rule where no submitted values match, should be pending' => [
            'skipConditions' => [
                // Skips if submitted interests include 'tech' OR 'sales'
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['tech', 'sales']], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            // user only submits 'marketing'
            'submissionData' => ['interests' => ['marketing']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        // Test for "Excluding" (NOT IN)
        yield 'Checkbox NOT IN rule where no submitted values match, should skip' => [
            'skipConditions' => [
                // Skips if submitted interests DO NOT include 'tech' OR 'sales'
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['tech', 'sales']], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            // user only submits 'marketing'
            'submissionData' => ['interests' => ['marketing']],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Checkbox NOT IN rule where one submitted value matches, should be pending' => [
            'skipConditions' => [
                // Skips if submitted interests DO NOT include 'tech' OR 'sales'
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['tech', 'sales']], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            // user submits 'marketing' and 'tech'
            'submissionData' => ['interests' => ['marketing', 'tech']],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Checkbox NOT IN with an empty submission, should skip' => [
            'skipConditions' => [
                // Skips if submitted interests DO NOT include 'tech' OR 'sales'
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['tech', 'sales']], 'field' => 'interests', 'type' => 'checkboxgrp', 'object' => 'form'],
            ],
            // user does not check any boxes
            'submissionData' => ['interests' => []],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        // --- RADIO TEST CASES ---

        yield 'Radio group EQUAL_TO exact value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'email'], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'email'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group EQUAL_TO different value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'email'], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'phone'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group NOT_EQUAL_TO different value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'email'], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'sms'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group NOT_EQUAL_TO same value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::NOT_EQUAL_TO, 'properties' => ['filter' => 'email'], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'email'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group IN with matching value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['email', 'sms']], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'email'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group IN with non-matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::INCLUDING_ANY, 'properties' => ['filter' => ['email', 'sms']], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'phone'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group NOT_IN with non-matching value, should skip' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['email', 'sms']], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'phone'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'Radio group NOT_IN with matching value, should be pending' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EXCLUDING_ANY, 'properties' => ['filter' => ['email', 'sms']], 'field' => 'contact_preference', 'type' => 'radiogrp', 'object' => 'form'],
            ],
            'submissionData' => ['contact_preference' => 'sms'],
            'expectedStatus' => FormDoiSubmission::STATUS_PENDING,
            'contactNumber'  => ++$contactNumber,
        ];

        // -- case-insensitive compare with UTF-8 chars

        yield 'UTF8 case-insensitive compare EQUAL_TO' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::EQUAL_TO, 'properties' => ['filter' => 'ольга'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['companyname' => 'ОЛЬГА'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'UTF8 case-insensitive compare STARTS_WITH' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::STARTS_WITH, 'properties' => ['filter' => 'Ол'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['companyname' => 'ОЛЬГА'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'UTF8 case-insensitive compare ENDS_WITH' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::ENDS_WITH, 'properties' => ['filter' => 'га'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['companyname' => 'ОЛЬГА'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];

        yield 'UTF8 case-insensitive compare CONTAINS' => [
            'skipConditions' => [
                ['glue' => 'and', 'operator' => OperatorOptions::CONTAINS, 'properties' => ['filter' => 'льг'], 'field' => 'companyname', 'type' => 'text', 'object' => 'company'],
            ],
            'submissionData' => ['companyname' => 'ОЛЬГА'],
            'expectedStatus' => FormDoiSubmission::STATUS_SKIPPED,
            'contactNumber'  => ++$contactNumber,
        ];
    }
}
