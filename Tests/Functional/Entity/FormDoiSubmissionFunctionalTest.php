<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Functional\Entity;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\FormFixtureHelper;
use MauticPlugin\LeuchtfeuerDoiBundle\Tests\Fixtures\PluginFixtureHelper;
use PHPUnit\Framework\Assert;

class FormDoiSubmissionFunctionalTest extends MauticMysqlTestCase
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

        Assert::assertInstanceOf(FormDoiSubmission::class, $doiSubmission);
        Assert::assertNull($doiSubmission->getForm());
        Assert::assertNull($doiSubmission->getFormSubmission());
        Assert::assertNotNull($doiSubmission->getLead());

        $lead = $doiSubmission->getLead();
        Assert::assertInstanceOf(Lead::class, $lead);
        Assert::assertSame($leadId, $lead->getId());
        $this->em->remove($lead);
        $this->em->flush();
        $this->em->clear();

        Assert::assertNull($this->em->getRepository(FormDoiSubmission::class)->find($submissionId));
    }
}
