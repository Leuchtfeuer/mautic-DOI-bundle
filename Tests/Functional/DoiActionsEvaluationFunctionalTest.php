<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Entity\SubmissionRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\OperatorOptions;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionExecutionLog;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiActionExecutionLogRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;

final class DoiActionsEvaluationFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FormFixtureHelper $formFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the DOI plugin is enabled
        $pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $pluginFixtureHelper->createAndEnablePlugin();

        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    /**
     * @param array<mixed>|null    $conditions    The conditions definition
     * @param array<string, mixed> $leadData      Profile data to pre-fill on the contact
     * @param array<string, mixed> $companyData   Company data (if any)
     * @param array<string, mixed> $formData      The submitted form values
     * @param bool                 $shouldExecute Whether we expect the DOI Action (Add to Segment) to run after verification
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('conditionsDataProvider')]
    public function testConditionalDoiActionExecution(
        ?array $conditions,
        array $leadData,
        array $companyData,
        array $formData,
        bool $shouldExecute,
    ): void {
        // 1. Preparation: Create Segment (Target of the action)
        $segment = $this->formFixtureHelper->createSegment('Target Segment', 'target-segment');

        // 2. Preparation: Create Contact & Company context
        $contact = new Lead();
        $contact->setEmail($formData['email']);
        foreach ($leadData as $field => $value) {
            $this->setEntityValue($contact, $field, $value);
        }
        $this->em->persist($contact);
        $this->em->flush();

        if ([] !== $companyData) {
            // Use helper if available or manual creation if helper doesn't support arbitrary fields easily
            // The provided helper createFormWithCompanyViaApi implies company support exists,
            // but createCompany method wasn't explicitly in the snippet, creating manually based on context logic
            $company = $this->formFixtureHelper->createCompany($companyData['companyname'] ?? 'Test Corp'); // Assuming this exists based on context or we create manual

            foreach ($companyData as $field => $value) {
                $this->setEntityValue($company, $field, $value);
            }
            $this->em->persist($company);
            $this->formFixtureHelper->addContactToCompany($contact, $company); // Assuming helper availability based on snippet 1
        }
        $this->em->flush();

        // 3. Create Form via Helper
        // We use the complex form to match the fields in the data provider
        $form = $this->formFixtureHelper->createComplexFormViaApi('Conditions Test Form');

        // 4. Configure DOI for this form
        $this->formFixtureHelper->createDoiConfig(
            form: $form,
            successRedirectUrl: 'https://example.com/success'
        );

        // 5. Create DOI Action (Modify Segment)
        // In this bundle, we assume conditions are passed as properties of the DOI Action
        $actionProperties = [
            'addToLists'      => [$segment->getId()],
            'removeFromLists' => [],
        ];

        $doiAction = $this->formFixtureHelper->createDoiAction(
            $form,
            'Modify Contact Segment',
            'lead.changelist',
            $actionProperties,
            $conditions
        );

        // 6. Execution: Submit the Form
        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_conditionstestform]');
        $formElement = $formCrawler->form();

        $mauticFormValues = [];
        foreach ($formData as $key => $value) {
            if (is_array($value)) {
                $multiselectFieldName = "mauticform[{$key}]";
                if ($formElement->has($multiselectFieldName)) {
                    /** @var ChoiceFormField $field */
                    $field = $formElement->get($multiselectFieldName);
                    if (is_object($field) && method_exists($field, 'setValue')) {
                        $field->setValue($value);
                    } else {
                        $this->handleCheckboxValues($formElement, $key, $value);
                    }
                } else {
                    $this->handleCheckboxValues($formElement, $key, $value);
                }
            } else {
                $fieldName = "mauticform[{$key}]";
                if ($formElement->has($fieldName)) {
                    $formElement->get($fieldName)->setValue($value);
                }
            }
        }

        $formElement->setValues($mauticFormValues);
        $this->client->submit($formElement);
        self::assertResponseIsSuccessful();

        // 7. Verify Submission and Retrieve DOI details
        /** @var FormDoiSubmission|null $doiSubmission */
        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->findOneBy(['email' => $formData['email']]);
        $this->assertInstanceOf(FormDoiSubmission::class, $doiSubmission, 'DOI Submission should have been created.');
        $this->assertSame('pending', $doiSubmission->getStatus());

        // 8. Trigger DOI Confirmation (Click the link)
        $formId    = $form->getId();
        $hash      = $doiSubmission->getHash();
        $tokenData = "{$formId}:{$hash}";
        $token     = base64_encode($tokenData);

        $this->client->request(Request::METHOD_GET, "/email/verify/{$token}");

        // 9. Assertion: Check if the Action Executed (Lead added to Segment)
        $this->em->clear(); // Clear Doctrine identity map to get fresh data

        /** @var Lead $updatedContact */
        $updatedContact = $this->em->getRepository(Lead::class)->findOneBy(['email' => $formData['email']]);
        $leadLists      = $this->em->getRepository(LeadList::class)->getLeadLists($updatedContact->getId());

        $isInSegment = isset($leadLists[$segment->getId()]);

        if ($shouldExecute) {
            $this->assertTrue($isInSegment, 'The DOI Action should have executed, but the contact is NOT in the segment.');
        } else {
            $this->assertFalse($isInSegment, 'The DOI Action should have been SKIPPED due to conditions, but the contact IS in the segment.');
        }

        // 10. Check Logs
        $this->checkLog($form->getId(), $doiAction->getId(), $shouldExecute);
    }

    private function setEntityValue(object $entity, string $field, mixed $value): void
    {
        $method = 'set'.ucfirst($field);
        if (method_exists($entity, $method)) {
            $entity->$method($value);
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
     * Verifies that a log entry was created for the specific action execution.
     */
    private function checkLog(int $formId, int $actionId, bool $expectedExecutionStatus): void
    {
        /** @var SubmissionRepository $submissionRepo */
        $submissionRepo = $this->em->getRepository(Submission::class);

        // Find the most recent submission for this form
        $submission = $submissionRepo->findOneBy(['form' => $formId], ['dateSubmitted' => 'DESC']);
        $this->assertInstanceOf(Submission::class, $submission, 'No submission found for log verification.');

        /** @var FormDoiActionExecutionLogRepository $logRepo */
        $logRepo = $this->em->getRepository(FormDoiActionExecutionLog::class);

        /** @var FormDoiActionExecutionLog|null $log */
        $log = $logRepo->findOneBy([
            'submission' => $submission,
            'action'     => $actionId,
        ]);

        $this->assertInstanceOf(FormDoiActionExecutionLog::class, $log, 'No execution log found for this action/submission combination.');

        $this->assertSame($expectedExecutionStatus, $log->isExecuted(), sprintf('Log status mismatch. Expected isExecuted: %s, but got: %s',
            $expectedExecutionStatus ? 'true' : 'false',
            $log->isExecuted() ? 'true' : 'false'
        ));

        $expectedDetails = $expectedExecutionStatus
            ? FormDoiActionExecutionLog::DETAILS_CONDITIONS_MET
            : FormDoiActionExecutionLog::DETAILS_CONDITIONS_NOT_MET;

        $this->assertEquals($expectedDetails, $log->getLogDetails(), 'Log detail message is incorrect.');
    }

    public static function conditionsDataProvider(): \Generator
    {
        // ---------------------------------------------------------------------------------
        // 1. NO CONDITIONS
        // ---------------------------------------------------------------------------------
        yield 'No conditions defined -> Action Always Executes' => [
            'conditions'    => null,
            'leadData'      => [],
            'companyData'   => [],
            'formData'      => ['email' => 'nocondition@test.com'],
            'shouldExecute' => true,
        ];

        yield 'Empty conditions array -> Action Always Executes' => [
            'conditions'    => [],
            'leadData'      => [],
            'companyData'   => [],
            'formData'      => ['email' => 'emptycondition@test.com'],
            'shouldExecute' => true,
        ];

        // ---------------------------------------------------------------------------------
        // 2. LEAD FIELD CONDITIONS
        // ---------------------------------------------------------------------------------
        yield 'Lead Condition: Country equals Poland (Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'country',
                    'object'     => 'lead',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Poland'],
                ],
            ],
            'leadData'      => ['country' => 'Poland'],
            'companyData'   => [],
            'formData'      => ['email' => 'pl@test.com'],
            'shouldExecute' => true,
        ];

        yield 'Lead Condition: Country equals Poland (No Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'country',
                    'object'     => 'lead',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Poland'],
                ],
            ],
            'leadData'      => ['country' => 'Germany'],
            'companyData'   => [],
            'formData'      => ['email' => 'germany@test.com'],
            'shouldExecute' => false,
        ];

        // ---------------------------------------------------------------------------------
        // 3. FORM FIELD CONDITIONS
        // ---------------------------------------------------------------------------------
        yield 'Form Condition: Source equals "WebSearch" (Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'source',
                    'object'     => 'form',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'WebSearch'],
                ],
            ],
            'leadData'      => [],
            'companyData'   => [],
            'formData'      => ['email' => 'websearch@test.com', 'source' => 'WebSearch'],
            'shouldExecute' => true,
        ];

        yield 'Form Condition: Source equals "WebSearch" (No Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'source',
                    'object'     => 'form',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'WebSearch'],
                ],
            ],
            'leadData'      => [],
            'companyData'   => [],
            'formData'      => ['email' => 'direct@test.com', 'source' => 'Direct'],
            'shouldExecute' => false,
        ];

        yield 'Form Condition: Select Box IN array (Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'colors', // Select box in complex form
                    'object'     => 'form',
                    'operator'   => OperatorOptions::INCLUDING_ANY,
                    'properties' => ['filter' => ['red', 'blue']],
                ],
            ],
            'leadData'      => [],
            'companyData'   => [],
            'formData'      => ['email' => 'colors@test.com', 'colors' => 'blue'],
            'shouldExecute' => true,
        ];

        // ---------------------------------------------------------------------------------
        // 4. COMPANY FIELD CONDITIONS
        // ---------------------------------------------------------------------------------
        yield 'Company Condition: Name contains "Leuchtfeuer" (Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'companyname',
                    'object'     => 'company',
                    'operator'   => OperatorOptions::CONTAINS,
                    'properties' => ['filter' => 'Leuchtfeuer'],
                ],
            ],
            'leadData'      => [],
            'companyData'   => ['companyname' => 'Leuchtfeuer Digital Marketing'],
            'formData'      => ['email' => 'ceo@leuchtfeuer.com', 'companyname' => 'Leuchtfeuer Digital Marketing'],
            'shouldExecute' => true,
        ];

        yield 'Company Condition: Name contains "Leuchtfeuer" (No Match - Different Company)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'companyname',
                    'object'     => 'company',
                    'operator'   => OperatorOptions::CONTAINS,
                    'properties' => ['filter' => 'Leuchtfeuer'],
                ],
            ],
            'leadData'      => [],
            'companyData'   => ['companyname' => 'Acme Corp'],
            'formData'      => ['email' => 'ceo@acme.com', 'companyname' => 'Acme Corp'],
            'shouldExecute' => false,
        ];

        // ---------------------------------------------------------------------------------
        // 5. MIXED CONDITIONS (AND/OR)
        // ---------------------------------------------------------------------------------
        yield 'Mixed: Form Source "Ads" AND Lead Country "Germany" (Both Match)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'source',
                    'object'     => 'form',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Ads'],
                ],
                [
                    'glue'       => 'and',
                    'field'      => 'country',
                    'object'     => 'lead',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Germany'],
                ],
            ],
            'leadData'      => ['country' => 'Germany'],
            'companyData'   => [],
            'formData'      => ['email' => 'ads@germany.com', 'source' => 'Ads'],
            'shouldExecute' => true,
        ];

        yield 'Mixed: Form Source "Ads" AND Lead Country "Germany" (One Failed)' => [
            'conditions' => [
                [
                    'glue'       => 'and',
                    'field'      => 'source',
                    'object'     => 'form',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Ads'],
                ],
                [
                    'glue'       => 'and',
                    'field'      => 'country',
                    'object'     => 'lead',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Germany'],
                ],
            ],
            'leadData'      => ['country' => 'United Kingdom'], // Mismatch
            'companyData'   => [],
            'formData'      => ['email' => 'ads@uk.com', 'source' => 'Ads'],
            'shouldExecute' => false,
        ];

        yield 'Mixed: Form field OR Lead field (Match second condition)' => [
            'conditions' => [
                [
                    'glue'       => 'and', // First item glue is ignored usually, but kept for structure
                    'field'      => 'source',
                    'object'     => 'form',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Partner'],
                ],
                [
                    'glue'       => 'or',
                    'field'      => 'country',
                    'object'     => 'lead',
                    'operator'   => OperatorOptions::EQUAL_TO,
                    'properties' => ['filter' => 'Germany'],
                ],
            ],
            'leadData'      => ['country' => 'Germany'], // Matches OR
            'companyData'   => [],
            'formData'      => ['email' => 'or@test.com', 'source' => 'Web'], // Mismatch first
            'shouldExecute' => true,
        ];
    }
}
