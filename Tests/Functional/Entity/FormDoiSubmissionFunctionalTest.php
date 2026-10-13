<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional\Entity;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;

final class FormDoiSubmissionFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    public function testSubmissionSurvivesFormDeletionUntilContactIsDeleted(): void
    {
        (new PluginFixtureHelper($this->em))->createAndEnablePlugin();
        $fixtureHelper = new FormFixtureHelper($this->em, $this->client);
        $form          = $fixtureHelper->createForm('DOI evidence form', 'doi-evidence-form');
        $doiSubmission = $fixtureHelper->createDoiSubmission($form, 'doi-evidence@example.com', new \DateTime());
        $submissionId  = $doiSubmission->getId();
        $leadId        = $doiSubmission->getLead()?->getId();

        $this->em->remove($form);
        $this->em->flush();
        $this->em->clear();

        $doiSubmission = $this->em->getRepository(FormDoiSubmission::class)->find($submissionId);

        $this->assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        $this->assertNotInstanceOf(\Mautic\FormBundle\Entity\Form::class, $doiSubmission->getForm());
        $this->assertNotInstanceOf(\Mautic\FormBundle\Entity\Submission::class, $doiSubmission->getFormSubmission());
        $lead = $doiSubmission->getLead();
        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame($leadId, $lead->getId());
        $this->em->remove($lead);
        $this->em->flush();
        $this->em->clear();

        $this->assertNotInstanceOf(FormDoiSubmission::class, $this->em->getRepository(FormDoiSubmission::class)->find($submissionId));
    }
}
