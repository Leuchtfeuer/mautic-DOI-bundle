<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Helper;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\MigrationHelper;
use PHPUnit\Framework\TestCase;

class MigrationHelperTest extends TestCase
{
    public function testRequireTableReturnsExistingTable(): void
    {
        $schema = new Schema();
        $table  = $schema->createTable('lf_forms');
        $table->addColumn('id', 'integer', ['unsigned' => true]);

        $resolved = MigrationHelper::requireTable($schema, 'lf_forms');

        $this->assertSame('lf_forms', $resolved->getName());
        $this->assertSame('INT UNSIGNED', MigrationHelper::getReferencedColumnType($resolved));
    }

    public function testRequireTableThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required table "forms" was not found');

        MigrationHelper::requireTable(new Schema(), 'forms');
    }

    public function testGetReferencedColumnTypeForSignedInt(): void
    {
        $table = new Table('forms');
        $table->addColumn('id', 'integer');

        $this->assertSame('INT', MigrationHelper::getReferencedColumnType($table));
    }
}
