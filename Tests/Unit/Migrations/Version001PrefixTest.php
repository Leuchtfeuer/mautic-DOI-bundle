<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use MauticPlugin\LeuchtfeuerDoiBundle\Migrations\Version_0_0_1;
use PHPUnit\Framework\TestCase;

class Version001PrefixTest extends TestCase
{
    public function testUpUsesPrefixedFormsTableInForeignKey(): void
    {
        $prefix = 'lf_';
        $schema = new Schema();
        $forms  = $schema->createTable($prefix.'forms');
        $forms->addColumn('id', 'integer', ['unsigned' => true]);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);

        /** @var list<string> $executedSql */
        $executedSql = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql[] = $sql;

                $statement = $this->createMock(\Doctrine\DBAL\Statement::class);
                $statement->method('executeStatement')->willReturn(1);

                return $statement;
            });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new Version_0_0_1($entityManager, $prefix);

        $this->assertTrue($migration->shouldExecute());
        $migration->execute();

        $this->assertCount(2, $executedSql);
        \assert(isset($executedSql[0], $executedSql[1]));
        $createSql = $executedSql[0];
        $alterSql  = $executedSql[1];
        $this->assertStringContainsString('CREATE TABLE `'.$prefix.'form_doi_actions`', $createSql);
        $this->assertStringContainsString('REFERENCES `'.$prefix.'forms`', $alterSql);
        $this->assertStringNotContainsString('REFERENCES forms ', $alterSql);
        $this->assertStringNotContainsString('REFERENCES `forms`', $alterSql);
    }

    public function testIsApplicableWhenPrefixedTableAlreadyExists(): void
    {
        $prefix = 'lf_';
        $schema = new Schema();
        $schema->createTable($prefix.'forms')->addColumn('id', 'integer');
        $schema->createTable($prefix.'form_doi_actions')->addColumn('id', 'integer');

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('introspectSchema')->willReturn($schema);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $migration = new Version_0_0_1($entityManager, $prefix);

        $this->assertFalse($migration->shouldExecute());
    }
}
