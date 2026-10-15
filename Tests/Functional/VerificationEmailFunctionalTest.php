<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Event\ResolveConsentSnapshotEvent;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email as MimeEmail;

final class VerificationEmailFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupTestData();

        $pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $pluginFixtureHelper->createAndEnablePlugin();
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
        $this->client->disableReboot();
        $form = $this->createFormViaApi('VerificationEmailTestForm'.uniqid());

        $verificationEmail = $this->createEmailViaApi('Verification Email '.uniqid());

        $this->createDoiConfig($form, $verificationEmail);

        $crawler     = $this->client->request(Request::METHOD_GET, "/form/{$form->getId()}");
        $formCrawler = $crawler->filter('form[id=mauticform_'.strtolower($form->getName()).']');
        $formElement = $formCrawler->form();
        $session     = $this->client->getRequest()->getSession();
        $session->set('consent_label_prefix', 'resolved ');
        $session->save();
        self::getContainer()->get('event_dispatcher')->addListener(
            DoiEvents::DOI_ON_RESOLVE_CONSENT_SNAPSHOT,
            static function (ResolveConsentSnapshotEvent $event): void {
                $prefix = $event->getRequest()->getSession()->get('consent_label_prefix');
                $event->transformLabels(static fn (string $label): string => $prefix.$label);
            }
        );
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
        $this->assertCount(1, $submissions);
        $doiSubmissions = $this->em->getRepository(FormDoiSubmission::class)->findAll();
        $this->assertCount(1, $doiSubmissions);
        $this->assertSame('pending', $doiSubmissions[0]->getStatus());
        $consentSnapshot = $doiSubmissions[0]->getSubmittedConsentSnapshot();
        $this->assertIsArray($consentSnapshot);
        $this->assertCount(1, $consentSnapshot['fields']);
        $this->assertSame('checkbox_group', $consentSnapshot['fields'][0]['alias']);
        $this->assertSame('resolved Checkbox group', $consentSnapshot['fields'][0]['label']);
        $this->assertSame('1', $consentSnapshot['fields'][0]['selected_options'][0]['value']);
        $this->assertSame('resolved events consent', $consentSnapshot['fields'][0]['selected_options'][0]['label']);

        $messages = $this->getMailerMessagesByToAddress('verifytest@example.com');
        $this->assertCount(1, $messages, 'Exactly one verification email should be sent');

        $message = $messages[0];
        $this->assertInstanceOf(MimeEmail::class, $message);
        $this->assertStringContainsString('verifytest@example.com', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Verify', (string) $message->getHtmlBody());

        $form->setCachedHtml('<form>Updated after submission</form>');
        $this->em->flush();
        $this->em->clear();

        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmissions[0]->getId());
        $this->assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        $this->assertEquals($consentSnapshot, $doiSubmission->getSubmittedConsentSnapshot());

        $form = $this->em->getRepository(Form::class)->find($form->getId());
        $this->assertInstanceOf(Form::class, $form);
        $this->em->remove($form);
        $this->em->flush();
        $this->em->clear();

        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($doiSubmission->getId());
        $this->assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        $this->assertEquals($consentSnapshot, $doiSubmission->getSubmittedConsentSnapshot());
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

        $this->assertArrayHasKey('form', $response, 'API should return form data');

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

        $this->assertArrayHasKey('email', $response, 'API should return email data');

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
