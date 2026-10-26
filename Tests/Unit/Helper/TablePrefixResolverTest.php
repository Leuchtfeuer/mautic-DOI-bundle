<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Helper\TablePrefixResolver;
use PHPUnit\Framework\TestCase;

class TablePrefixResolverTest extends TestCase
{
    /**
     * @dataProvider provideFormTableNames
     */
    public function testResolveDerivesPrefixFromFormMetadata(string $formsTableName, string $expectedPrefix): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getTableName')
            ->willReturn($formsTableName);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with(Form::class)
            ->willReturn($metadata);

        $this->assertSame($expectedPrefix, TablePrefixResolver::resolve($entityManager));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideFormTableNames(): iterable
    {
        yield 'no prefix' => ['forms', ''];
        yield 'underscore prefix' => ['lf_forms', 'lf_'];
        yield 'custom prefix' => ['acmeforms', 'acme'];
    }
}
