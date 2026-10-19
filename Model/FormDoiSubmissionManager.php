<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmission;

class FormDoiSubmissionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(FormDoiSubmission $doiSubmission): FormDoiSubmission
    {
        $this->entityManager->persist($doiSubmission);
        $this->entityManager->flush();

        return $doiSubmission;
    }
}
