<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Resolves the real DB table prefix from Form metadata.
 *
 * Core PluginDatabase injects env MAUTIC_TABLE_PREFIX, which is often empty even when
 * local.php db_table_prefix (exported as MAUTIC_DB_TABLE_PREFIX) is set. Install already
 * used TablePrefixResolver; update/reload must do the same.
 *
 * Lives outside Migrations/ so IntegrationsBundle\Migration\Engine does not treat it as a migration.
 */
abstract class AbstractPrefixedMigration extends AbstractMigration
{
    public function __construct(EntityManagerInterface $entityManager, string $tablePrefix)
    {
        parent::__construct($entityManager, TablePrefixResolver::resolve($entityManager) ?: $tablePrefix);
    }
}
