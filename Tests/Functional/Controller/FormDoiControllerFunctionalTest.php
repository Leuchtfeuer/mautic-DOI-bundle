<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiAction;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Model\DoiConfigManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use Symfony\Component\DomCrawler\Crawler;

class FormDoiControllerFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private DoiConfigManager $doiConfigManager;

    private PluginFixtureHelper $pluginFixtureHelper;

    private FormFixtureHelper $formFixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginFixtureHelper = new PluginFixtureHelper($this->em);
        $this->pluginFixtureHelper->createAndEnablePlugin();
        $this->doiConfigManager  = $this->getContainer()->get(DoiConfigManager::class);
        $this->formFixtureHelper = new FormFixtureHelper($this->em, $this->client);
    }

    /**
     * Test that DOI config can be saved when enabled.
     */
    public function testSaveDoiConfigWhenEnabled(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test DOI Form', 'test_doi_form');
        $verificationEmail = $this->createEmail('DOI Verification Email');
        $followUpEmail     = $this->createEmail('DOI Follow-up Email');

        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][skipOnCookie]'        => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
            'mauticform[doiConfig][followUpEmailId]'     => $followUpEmail->getId(),
            'mauticform[doiConfig][successRedirectUrl]'  => 'https://example.com/success',
            'mauticform[doiConfig][errorRedirectUrl]'    => 'https://example.com/error',
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify DOI config was saved
        $savedDoiConfig = $this->doiConfigManager->getFormDoiConfig($form);
        $this->assertNotNull($savedDoiConfig);
        $this->assertTrue($savedDoiConfig->isEnabled());
        $this->assertTrue($savedDoiConfig->isSkipOnCookie());
        $this->assertEquals($verificationEmail->getId(), $savedDoiConfig->getVerificationEmail()->getId());
        $this->assertEquals($followUpEmail->getId(), $savedDoiConfig->getFollowUpEmail()->getId());
        $this->assertEquals('https://example.com/success', $savedDoiConfig->getSuccessRedirectUrl());
        $this->assertEquals('https://example.com/error', $savedDoiConfig->getErrorRedirectUrl());
    }

    /**
     * Test that DOI config can be saved when disabled.
     */
    public function testSaveDoiConfigWhenDisabled(): void
    {
        $form = $this->formFixtureHelper->createForm('Test DOI Form Disabled', 'test_doi_form_disabled');

        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]' => '0',
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify DOI config was saved as disabled
        $savedDoiConfig = $this->doiConfigManager->getFormDoiConfig($form);
        $this->assertNotNull($savedDoiConfig);
        $this->assertFalse($savedDoiConfig->isEnabled());
    }

    /**
     * Test that validation fails when DOI is enabled but verification email is not provided.
     */
    public function testValidationFailsWhenEnabledWithoutVerificationEmail(): void
    {
        $form = $this->formFixtureHelper->createForm('Test DOI Validation', 'test_doi_validation');

        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]' => '1',
            // No verification email provided
        ]);

        $crawler  = $this->client->submit($formElement);
        $response = $this->client->getResponse();
        $this->assertTrue($response->isOk());

        // Check that validation error is displayed
        $validationText = 'Verification email is required when DOI is enabled';
        $pageContent    = $crawler->filter('body')->text();
        $this->assertStringContainsString($validationText, $pageContent, 'Validation text not found on the page');
    }

    /**
     * Test that validation passes when DOI is disabled even without verification email.
     */
    public function testValidationPassesWhenDisabledWithoutVerificationEmail(): void
    {
        $form = $this->formFixtureHelper->createForm('Test DOI Disabled Validation', 'test_doi_disabled_validation');

        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]' => '0',
            // No verification email provided, but that's OK when disabled
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify DOI config was saved as disabled
        $savedDoiConfig = $this->doiConfigManager->getFormDoiConfig($form);
        $this->assertNotNull($savedDoiConfig);
        $this->assertFalse($savedDoiConfig->isEnabled());
    }

    public function testValidationFailsWhenSkipPostActionRedirectHasNoUrl(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test Empty Skip Redirect', 'test_empty_skip_redirect');
        $verificationEmail = $this->createEmail('Skip Redirect Verification Email');

        $crawler     = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'                => '1',
            'mauticform[doiConfig][verificationEmailId]'    => $verificationEmail->getId(),
            'mauticform[doiConfig][skipPostAction]'         => 'redirect',
            'mauticform[doiConfig][skipPostActionProperty]' => '',
        ]);

        $crawler = $this->client->submit($formElement);

        $this->assertTrue($this->client->getResponse()->isOk());
        $this->assertStringContainsString('Fill in a valid URL.', $crawler->filter('body')->text());
    }

    public function testValidationAllowsEmptySkipPostActionPropertyForReturnAction(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test Empty Skip Return', 'test_empty_skip_return');
        $verificationEmail = $this->createEmail('Skip Return Verification Email');

        $crawler     = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'                => '1',
            'mauticform[doiConfig][verificationEmailId]'    => $verificationEmail->getId(),
            'mauticform[doiConfig][skipPostAction]'         => 'return',
            'mauticform[doiConfig][skipPostActionProperty]' => '',
        ]);

        $this->client->submit($formElement);

        $this->assertTrue($this->client->getResponse()->isOk());
        $savedDoiConfig = $this->doiConfigManager->getFormDoiConfig($form);
        $this->assertNotNull($savedDoiConfig);
        $this->assertSame('return', $savedDoiConfig->getSkipPostAction());
    }

    /**
     * Test that existing DOI config is loaded correctly in the form.
     */
    public function testExistingDoiConfigIsLoadedInForm(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test Existing DOI Config', 'test_existing_doi_config');
        $verificationEmail = $this->createEmail('Existing Verification Email');

        // Create DOI config directly
        $doiConfig = new FormDoiConfig();
        $doiConfig->setForm($form);
        $doiConfig->setEnabled(true);
        $doiConfig->setSkipOnCookie(true);
        $doiConfig->setVerificationEmail($verificationEmail);
        $doiConfig->setSuccessRedirectUrl('https://example.com/existing-success');
        $this->em->persist($doiConfig);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify that existing values are loaded
        $enabledField = $crawler->filter('input[name="mauticform[doiConfig][enabled]"]:checked');
        $this->assertEquals('1', $enabledField->attr('value'));

        $skipOnCookieField = $crawler->filter('input[name="mauticform[doiConfig][skipOnCookie]"]:checked');
        $this->assertEquals('1', $skipOnCookieField->attr('value'));

        $verificationEmailField = $crawler->filter('select[name="mauticform[doiConfig][verificationEmailId]"] option:selected');
        $this->assertEquals($verificationEmail->getId(), $verificationEmailField->attr('value'));

        $successUrlField = $crawler->filter('input[name="mauticform[doiConfig][successRedirectUrl]"]');
        $this->assertEquals('https://example.com/existing-success', $successUrlField->attr('value'));
    }

    /**
     * Test that an existing DOI config can be updated.
     */
    public function testUpdateExistingDoiConfig(): void
    {
        // Create initial form and emails
        $form                     = $this->formFixtureHelper->createForm('Test DOI Update Form', 'test_doi_update_form');
        $initialVerificationEmail = $this->createEmail('Initial Verification Email');
        $initialFollowUpEmail     = $this->createEmail('Initial Follow-up Email');

        // Create initial DOI config
        $initialDoiConfig = new FormDoiConfig();
        $initialDoiConfig->setForm($form);
        $initialDoiConfig->setEnabled(true);
        $initialDoiConfig->setSkipOnCookie(true);
        $initialDoiConfig->setVerificationEmail($initialVerificationEmail);
        $initialDoiConfig->setFollowUpEmail($initialFollowUpEmail);
        $initialDoiConfig->setSuccessRedirectUrl('https://example.com/initial-success');
        $initialDoiConfig->setErrorRedirectUrl('https://example.com/initial-error');
        $this->em->persist($initialDoiConfig);
        $this->em->flush();

        // Create new emails for update
        $newVerificationEmail = $this->createEmail('New Verification Email');
        $newFollowUpEmail     = $this->createEmail('New Follow-up Email');

        // Request the edit form
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        // Update the form with new values
        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][skipOnCookie]'        => '0',
            'mauticform[doiConfig][verificationEmailId]' => $newVerificationEmail->getId(),
            'mauticform[doiConfig][followUpEmailId]'     => $newFollowUpEmail->getId(),
            'mauticform[doiConfig][successRedirectUrl]'  => 'https://example.com/updated-success',
            'mauticform[doiConfig][errorRedirectUrl]'    => 'https://example.com/updated-error',
        ]);

        // Submit the updated form
        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Refresh the entity manager to ensure we're getting the latest data
        $this->em->clear();

        // Retrieve the updated DOI config
        $updatedDoiConfig = $this->doiConfigManager->getFormDoiConfig($form);

        // Assert that the config was updated correctly
        $this->assertNotNull($updatedDoiConfig);
        $this->assertTrue($updatedDoiConfig->isEnabled());
        $this->assertFalse($updatedDoiConfig->isSkipOnCookie());
        $this->assertEquals($newVerificationEmail->getId(), $updatedDoiConfig->getVerificationEmail()->getId());
        $this->assertEquals($newFollowUpEmail->getId(), $updatedDoiConfig->getFollowUpEmail()->getId());
        $this->assertEquals('https://example.com/updated-success', $updatedDoiConfig->getSuccessRedirectUrl());
        $this->assertEquals('https://example.com/updated-error', $updatedDoiConfig->getErrorRedirectUrl());

        // Assert that the config is the same entity as the initial one (updated, not new)
        $this->assertEquals($initialDoiConfig->getId(), $updatedDoiConfig->getId());
    }

    /**
     * Ensure DOI form fields are not available when the plugin is disabled.
     */
    public function testDoiFieldsAreHiddenWhenPluginDisabled(): void
    {
        $this->pluginFixtureHelper->disablePlugin();

        $form = $this->formFixtureHelper->createForm('Test DOI Disabled - Fields Hidden', 'test_doi_fields_hidden_when_disabled');

        // Load the form edit page
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        // Assert DOI fields are not present
        $this->assertCount(0, $crawler->filter('input[name="mauticform[doiConfig][enabled]"]'), 'Enabled field should not be present');
        $this->assertCount(0, $crawler->filter('input[name="mauticform[doiConfig][skipOnCookie]"]'), 'Skip on cookie field should not be present');
        $this->assertCount(0, $crawler->filter('select[name="mauticform[doiConfig][verificationEmailId]"]'), 'Verification email field should not be present');
        $this->assertCount(0, $crawler->filter('select[name="mauticform[doiConfig][followUpEmailId]"]'), 'Follow-up email field should not be present');
        $this->assertCount(0, $crawler->filter('input[name="mauticform[doiConfig][successRedirectUrl]"]'), 'Success redirect URL field should not be present');
        $this->assertCount(0, $crawler->filter('input[name="mauticform[doiConfig][errorRedirectUrl]"]'), 'Error redirect URL field should not be present');
    }

    public function testSaveFormWithDoiActions(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test DOI Form with Actions', 'test_doi_form_with_actions');
        $verificationEmail = $this->createEmail('DOI Verification Email');
        $sessionId         = (string) $form->getId();

        $this->submitNewDoiActionForm($sessionId);
        $this->assertDoiActionInSession($sessionId);

        $sessionData = $this->storeSessionData();

        // Get the form edit page
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $this->restoreSessionData($sessionData);

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();

        // Now submit the main form with DOI config, including sessionId
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
            'mauticform[sessionId]'                      => $sessionId,
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify DOI action is persisted in database
        $doiActionRepository = $this->em->getRepository(FormDoiAction::class);
        $savedActions        = $doiActionRepository->findBy(['form' => $form]);
        $this->assertCount(1, $savedActions);

        $savedAction = $savedActions[0];
        $this->assertEquals('form.email', $savedAction->getType());
        $this->assertEquals('Test email subject', $savedAction->getProperties()['subject']);
        $this->assertEquals('Test email message', $savedAction->getProperties()['message']);
    }

    public function testEditFormDoiAction(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test DOI Form with Actions', 'test_doi_form_with_actions');
        $verificationEmail = $this->createEmail('DOI Verification Email');
        $sessionId         = (string) $form->getId();

        // Create initial action
        $this->submitNewDoiActionForm($sessionId);
        $this->assertDoiActionInSession($sessionId);

        $sessionData = $this->storeSessionData();

        // Get the form edit page
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $this->restoreSessionData($sessionData);

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();

        // Now submit the main form with DOI config, including sessionId
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        $doiActionRepository = $this->em->getRepository(FormDoiAction::class);
        $savedActions        = $doiActionRepository->findBy(['form' => $form]);
        $this->assertCount(1, $savedActions);
        $doiAction = $savedActions[0];

        $this->submitEditDoiActionForm($sessionId, $doiAction->getId());

        $this->assertDoiActionInSession($sessionId);

        $sessionData = $this->storeSessionData();

        // Get the form edit page
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $this->restoreSessionData($sessionData);

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();

        // Now submit the main form with DOI config, including sessionId
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify the action was updated
        $updatedAction = $doiActionRepository->find($doiAction->getId());
        $this->assertEquals('Updated DOI Email Action', $updatedAction->getName());
        $this->assertEquals('Updated action description', $updatedAction->getDescription());
        $this->assertEquals('Updated email subject', $updatedAction->getProperties()['subject']);
        $this->assertEquals('Updated email message', $updatedAction->getProperties()['message']);
    }

    public function testRemoveFormDoiAction(): void
    {
        $form              = $this->formFixtureHelper->createForm('Test DOI Form with Actions', 'test_doi_form_with_actions');
        $verificationEmail = $this->createEmail('DOI Verification Email');
        $sessionId         = (string) $form->getId();

        // Create initial action
        $this->submitNewDoiActionForm($sessionId);
        $this->assertDoiActionInSession($sessionId);

        $sessionData = $this->storeSessionData();

        // Get the form edit page
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());

        $this->restoreSessionData($sessionData);

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();

        // Submit the main form with DOI config, including sessionId
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
            'mauticform[sessionId]'                      => $sessionId,
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify DOI action is persisted in database
        $doiActionRepository = $this->em->getRepository(FormDoiAction::class);
        $savedActions        = $doiActionRepository->findBy(['form' => $form]);
        $this->assertCount(1, $savedActions);
        $doiAction = $savedActions[0];

        // Remove the DOI action
        $this->client->request(
            'POST',
            sprintf('/s/forms-doi/action/delete/%d?formId=%d', $doiAction->getId(), $form->getId()),
            [],
            [],
            $this->createAjaxHeaders()
        );
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify the action was removed from the session
        $this->assertDoiActionNotInSession($sessionId);
        $sessionData = $this->storeSessionData();

        // Save the form again to persist changes
        $crawler = $this->client->request('GET', sprintf('/s/forms/edit/%d', $form->getId()));
        $this->assertTrue($this->client->getResponse()->isOk());
        $this->restoreSessionData($sessionData);

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[doiConfig][enabled]'             => '1',
            'mauticform[doiConfig][verificationEmailId]' => $verificationEmail->getId(),
            'mauticform[sessionId]'                      => $sessionId,
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk());

        // Verify the action was removed from the database
        $remainingActions = $doiActionRepository->findBy(['form' => $form]);
        $this->assertCount(0, $remainingActions, 'DOI action should be removed from the database');
    }

    public function testCloneFormPersistsDoiConfigAndActions(): void
    {
        $form = $this->formFixtureHelper->createForm('Original DOI Clone Form', 'original_doi_clone_form');

        $verificationEmail = $this->formFixtureHelper->createEmail('Clone Verification Email', '<p>Verification</p>');
        $followUpEmail     = $this->formFixtureHelper->createEmail('Clone Follow-up Email', '<p>Follow-up</p>');

        $originalConfig = $this->formFixtureHelper->createDoiConfig(
            $form,
            $verificationEmail,
            $followUpEmail,
            true,
            'https://example.com/original-success',
            'https://example.com/original-error'
        );
        $originalConfig->setSkipOnCookie(true);
        $this->em->flush();

        $originalAction = $this->formFixtureHelper->createDoiAction(
            $form,
            'Original DOI Email Action',
            'form.email',
            [
                'subject' => 'Original subject',
                'message' => 'Original message',
            ]
        );

        $originalFormId      = (int) $form->getId();
        $originalConfigId    = (int) $originalConfig->getId();
        $originalActionId    = (int) $originalAction->getId();
        $verificationEmailId = (int) $verificationEmail->getId();
        $followUpEmailId     = (int) $followUpEmail->getId();

        $crawler  = $this->client->request('GET', sprintf('/s/forms/clone/%d', $originalFormId));
        $response = $this->client->getResponse();
        $this->assertTrue($response->isOk(), 'Expected clone endpoint to render form edit page with status 200.');

        $idField = $crawler->filter('input[name="mauticform[id]"]');
        if (0 !== $idField->count()) {
            $this->assertSame('', (string) $idField->attr('value'), 'Cloned form should not have an id until saved.');
        }

        $sessionIdField = $crawler->filter('input[name="mauticform[sessionId]"]');
        $this->assertCount(1, $sessionIdField, 'Cloned form edit page must contain session id field.');
        $sessionId = (string) $sessionIdField->attr('value');
        $this->assertNotSame('', $sessionId, 'Session id should not be empty for cloned form.');

        $enabledField = $crawler->filter('input[name="mauticform[doiConfig][enabled]"]:checked');
        $this->assertGreaterThan(0, $enabledField->count(), 'Enabled field should be checked on cloned form.');
        $this->assertSame('1', $enabledField->attr('value'));

        $skipOnCookieField = $crawler->filter('input[name="mauticform[doiConfig][skipOnCookie]"]:checked');
        $this->assertGreaterThan(0, $skipOnCookieField->count(), 'Skip on cookie field should be checked on cloned form.');
        $this->assertSame('1', $skipOnCookieField->attr('value'));

        $selectedVerificationEmail = $crawler->filter('select[name="mauticform[doiConfig][verificationEmailId]"] option:selected');
        $this->assertGreaterThan(0, $selectedVerificationEmail->count(), 'Verification email should be preselected.');
        $this->assertSame((string) $verificationEmailId, $selectedVerificationEmail->attr('value'));

        $selectedFollowUpEmail = $crawler->filter('select[name="mauticform[doiConfig][followUpEmailId]"] option:selected');
        $this->assertGreaterThan(0, $selectedFollowUpEmail->count(), 'Follow-up email should be preselected.');
        $this->assertSame((string) $followUpEmailId, $selectedFollowUpEmail->attr('value'));

        $successRedirectField = $crawler->filter('input[name="mauticform[doiConfig][successRedirectUrl]"]');
        $this->assertSame('https://example.com/original-success', (string) $successRedirectField->attr('value'));

        $errorRedirectField = $crawler->filter('input[name="mauticform[doiConfig][errorRedirectUrl]"]');
        $this->assertSame('https://example.com/original-error', (string) $errorRedirectField->attr('value'));

        $clonedName  = 'Original DOI Clone Form - cloned';

        $formElement = $crawler->filterXPath('//form[@name="mauticform"]')->form();
        $formElement->setValues([
            'mauticform[name]'                                => $clonedName,
        ]);

        $this->client->submit($formElement);
        $this->assertTrue($this->client->getResponse()->isOk(), 'Saving cloned form failed.');

        $this->em->clear();

        $formRepository = $this->em->getRepository(Form::class);
        $originalForm   = $formRepository->find($originalFormId);
        $clonedForm     = $formRepository->findOneBy(['name' => $clonedName]);
        $this->assertInstanceOf(Form::class, $originalForm);
        $this->assertInstanceOf(Form::class, $clonedForm, 'Cloned form should be persisted.');
        $this->assertNotSame($originalForm->getId(), $clonedForm->getId(), 'Cloned form must have a different id.');

        $configRepository       = $this->em->getRepository(FormDoiConfig::class);
        $originalConfigReloaded = $configRepository->findOneBy(['form' => $originalForm]);
        $clonedConfig           = $configRepository->findOneBy(['form' => $clonedForm]);
        $this->assertNotNull($originalConfigReloaded, 'Original form should retain its DOI config.');
        $this->assertSame($originalConfigId, $originalConfigReloaded->getId(), 'Original config should remain unchanged.');
        $this->assertNotNull($clonedConfig, 'Cloned form should have DOI config.');
        $this->assertNotSame($originalConfigId, $clonedConfig->getId(), 'Cloned config should be a new entity.');
        $this->assertTrue($clonedConfig->isEnabled());
        $this->assertTrue($clonedConfig->isSkipOnCookie());
        $this->assertSame($verificationEmailId, $clonedConfig->getVerificationEmail()->getId());
        $this->assertSame($followUpEmailId, $clonedConfig->getFollowUpEmail()->getId());
        $this->assertSame('https://example.com/original-success', $clonedConfig->getSuccessRedirectUrl());
        $this->assertSame('https://example.com/original-error', $clonedConfig->getErrorRedirectUrl());

        $doiActionRepository = $this->em->getRepository(FormDoiAction::class);
        $originalActions     = $doiActionRepository->findBy(['form' => $originalForm]);
        $clonedActions       = $doiActionRepository->findBy(['form' => $clonedForm]);
        $this->assertCount(1, $originalActions, 'Original form should still have its DOI action.');
        $this->assertSame($originalActionId, $originalActions[0]->getId(), 'Original DOI action should remain unchanged.');
        $this->assertCount(1, $clonedActions, 'Cloned form should have exactly one DOI action.');
        $clonedAction = $clonedActions[0];
        $this->assertNotSame($originalActionId, $clonedAction->getId(), 'Cloned action should be a new entity.');
        $this->assertSame('Original DOI Email Action', $clonedAction->getName());
        $this->assertSame('form.email', $clonedAction->getType());
        $this->assertSame('Original subject', $clonedAction->getProperties()['subject'] ?? null);
        $this->assertSame('Original message', $clonedAction->getProperties()['message'] ?? null);
        $this->assertSame(1, $clonedAction->getOrder());
    }

    private function submitNewDoiActionForm(string $sessionId): void
    {
        $this->client->request(
            'GET',
            '/s/forms-doi/action/new',
            [
                'formId' => $sessionId,
                'type'   => 'form.email',
            ],
            [],
            $this->createAjaxHeaders()
        );
        $this->assertTrue($this->client->getResponse()->isOk());

        $content    = json_decode($this->client->getResponse()->getContent())->newContent;
        $crawler    = new Crawler($content, $this->client->getInternalRequest()->getUri());
        $actionForm = $crawler->filter('form')->form();

        $actionForm->setValues([
            'formaction[properties][subject]' => 'Test email subject',
            'formaction[properties][message]' => 'Test email message',
            'formaction[name]'                => 'Test DOI Email Action',
            'formaction[description]'         => 'Test action description',
            'formaction[type]'                => 'form.email',
            'formaction[formId]'              => $sessionId,
        ]);
        $this->client->submit($actionForm, [], $this->createAjaxHeaders());
        $this->assertTrue($this->client->getResponse()->isOk());
    }

    private function submitEditDoiActionForm(string $sessionId, int $doiActionId): void
    {
        $this->client->request(
            'GET',
            sprintf('/s/forms-doi/action/edit/%d', $doiActionId),
            [
                'formId' => $sessionId,
                'type'   => 'form.email',
            ],
            [],
            $this->createAjaxHeaders()
        );
        $this->assertTrue($this->client->getResponse()->isOk());

        $content    = json_decode($this->client->getResponse()->getContent())->newContent;
        $crawler    = new Crawler($content, $this->client->getInternalRequest()->getUri());
        $actionForm = $crawler->filter('form')->form();

        $actionForm->setValues([
            'formaction[properties][subject]' => 'Updated email subject',
            'formaction[properties][message]' => 'Updated email message',
            'formaction[name]'                => 'Updated DOI Email Action',
            'formaction[description]'         => 'Updated action description',
            'formaction[type]'                => 'form.email',
            'formaction[formId]'              => $sessionId,
        ]);
        $this->client->submit($actionForm, [], $this->createAjaxHeaders());
        $this->assertTrue($this->client->getResponse()->isOk());
    }

    private function assertDoiActionNotInSession(string $sessionId): void
    {
        $sessionManager   = $this->getContainer()->get('MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager');
        $actionsInSession = $sessionManager->getActionsFromSession($sessionId);
        $this->assertEmpty($actionsInSession, 'Actions should not be in session');
    }

    private function assertDoiActionInSession(string $sessionId): void
    {
        $sessionManager   = $this->getContainer()->get('MauticPlugin\LeuchtfeuerDoiBundle\Service\FormDoiActionSessionManager');
        $actionsInSession = $sessionManager->getActionsFromSession($sessionId);
        $this->assertNotEmpty($actionsInSession, 'Actions should be in session');
    }

    /**
     * @return array<string, mixed>
     */
    private function storeSessionData(): array
    {
        return $this->client->getRequest()->getSession()->all();
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function restoreSessionData(array $sessionData): void
    {
        foreach ($sessionData as $key => $value) {
            $this->client->getRequest()->getSession()->set($key, $value);
        }
    }

    private function createEmail(string $name): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject('Test Subject');
        $email->setCustomHtml('<p>Test content</p>');
        $email->setEmailType('template');
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }
}
