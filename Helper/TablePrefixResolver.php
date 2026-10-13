<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Form;

/**
 * Resolves the Doctrine/Mautic table prefix used for core tables.
 *
 * Prefers the prefix already applied to Form metadata (same source as
 * DoctrineEventsSubscriber) so plugin migrations stay aligned with the DB
 * even when factory/container/constant values diverge.
 */
final class TablePrefixResolver
{
    public static function resolve(EntityManagerInterface $entityManager): string
    {
        $formsTable = $entityManager->getClassMetadata(Form::class)->getTableName();

        if (str_ends_with($formsTable, 'forms')) {
            return substr($formsTable, 0, -strlen('forms'));
        }

        if (defined('MAUTIC_TABLE_PREFIX')) {
            return (string) MAUTIC_TABLE_PREFIX;
        }

        return '';
    }
}
