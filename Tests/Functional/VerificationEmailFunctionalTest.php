<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email as MimeEmail;

class VerificationEmailFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private PluginFixtureHelper $pluginFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupTestData();

        $this->pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $this->pluginFixtureHelper->createAndEnablePlugin();
    }

    private function cleanupTestData(): void
    {
        $connection = $this->em->getConnection();
        $doiTables  = [
            'test_form_doi_action_execution_logs',
            'test_form_doi_action_conditions',
            'test_form_doi_actions',
            'test_form_doi_submissions',
            'test_form_doi_config',
        ];

        foreach ($doiTables as $table) {
            try {
                $connection->executeStatement("DELETE FROM {$table}");
            } catch (\Exception) {
            }
        }

        try {
            $connection->executeStatement("DELETE FROM test_plugin_integration_settings WHERE name = 'LeuchtfeuerDoi'");
        } catch (\Exception) {
        }
        try {
            $connection->executeStatement("DELETE FROM test_plugins WHERE bundle = 'LeuchtfeuerDoiBundle'");
        } catch (\Exception) {
        }
    }

    public function testVerificationEmailIsSentOnFormSubmit(): void
    {
        $form = $this->createFormViaApi('VerificationEmailTestForm'.uniqid());

        $verificationEmail = $this->createEmailViaApi('Verification Email '.uniqid());

        $this->createDoiConfig($form, $verificationEmail);

        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_'.strtolower($form->getName()).']');
        $formElement = $formCrawler->form();
        $formElement->setValues([
            'mauticform[email]' => 'verifytest@example.com',
        ]);
        foreach ($formElement->all() as $fieldName => $field) {
            if (preg_match('/^mauticform\[checkbox_group]\[\d+]$/', $fieldName)
                && $field instanceof ChoiceFormField
                && in_array('1', $field->availableOptionValues(), true)) {
                $field->tick();
            }
        }
        $this->client->submit($formElement);
        $this->assertResponseIsSuccessful();

        $submissions = $this->em->getRepository(Submission::class)->findAll();
        Assert::assertCount(1, $submissions);
        $doiSubmissions = $this->em->getRepository(FormDoiSubmission::class)->findAll();
        Assert::assertCount(1, $doiSubmissions);
        Assert::assertSame('pending', $doiSubmissions[0]->getStatus());
        $consentSnapshot = $doiSubmissions[0]->getSubmittedConsentSnapshot();
        Assert::assertIsArray($consentSnapshot);
        Assert::assertCount(1, $consentSnapshot['fields']);
        Assert::assertSame('checkbox_group', $consentSnapshot['fields'][0]['alias']);
        Assert::assertSame('Checkbox group', $consentSnapshot['fields'][0]['label']);
        Assert::assertSame('1', $consentSnapshot['fields'][0]['selected_options'][0]['value']);
        Assert::assertSame('events consent', $consentSnapshot['fields'][0]['selected_options'][0]['label']);

        $messages = $this->getMailerMessagesByToAddress('verifytest@example.com');
        Assert::assertCount(1, $messages, 'Exactly one verification email should be sent');

        $message = $messages[0];
        Assert::assertInstanceOf(MimeEmail::class, $message);
        Assert::assertStringContainsString('verifytest@example.com', $message->getTo()[0]->getAddress());
        Assert::assertStringContainsString('Verify', (string) $message->getHtmlBody());

        $form->setCachedHtml('<form>Updated after submission</form>');
        $this->em->flush();
        $this->em->clear();

        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissions[0]->getId());
        Assert::assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        Assert::assertSame($consentSnapshot, $doiSubmission->getSubmittedConsentSnapshot());

        $form = $this->em->getRepository(Form::class)->find($form->getId());
        Assert::assertInstanceOf(Form::class, $form);
        $this->em->remove($form);
        $this->em->flush();
        $this->em->clear();

        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmission->getId());
        Assert::assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        Assert::assertSame($consentSnapshot, $doiSubmission->getSubmittedConsentSnapshot());
    }

    private function createFormViaApi(string $name): Form
    {
        $alias = strtolower($name);

        $payload = [
            'name'        => $name,
            'alias'       => $alias,
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
                    'label'      => 'Checkbox group',
                    'alias'      => 'checkbox_group',
                    'type'       => 'checkboxgrp',
                    'properties' => [
                        'syncList'   => 0,
                        'optionlist' => [
                            'list' => [
                                ['label' => 'events consent', 'value' => '1'],
                            ],
                        ],
                    ],
                ],
                [
                    'label' => 'Submit',
                    'type'  => 'button',
                ],
            ],
            'postAction' => 'return',
        ];

        $this->client->request(Request::METHOD_POST, '/api/forms/new', $payload);
        $response = json_decode($this->client->getResponse()->getContent(), true);

        Assert::assertArrayHasKey('form', $response, 'API should return form data');

        $formId = $response['form']['id'];

        return $this->em->getRepository(Form::class)->find($formId);
    }

    private function createEmailViaApi(string $name): Email
    {
        $payload = [
            'name'         => $name,
            'subject'      => 'Please verify your email',
            'emailType'    => 'template',
            'isPublished'  => true,
            'customHtml'   => '<!DOCTYPE html><html><body><p>Please click the link: <a href="{doi_link}">Verify</a></p></body></html>',
            'fromAddress'  => 'test@example.com',
            'fromName'     => 'Test Sender',
        ];

        $this->client->request(Request::METHOD_POST, '/api/emails/new', $payload);
        $response = json_decode($this->client->getResponse()->getContent(), true);

        Assert::assertArrayHasKey('email', $response, 'API should return email data');

        $emailId = $response['email']['id'];

        return $this->em->getRepository(Email::class)->find($emailId);
    }

    private function createDoiConfig(Form $form, Email $verificationEmail): FormDoiConfig
    {
        $config = new FormDoiConfig();
        $config->setForm($form);
        $config->setEnabled(true);
        $config->setVerificationEmail($verificationEmail);
        $config->setSuccessRedirectUrl('https://example.com/success');
        $config->setErrorRedirectUrl('https://example.com/error');

        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }
}
