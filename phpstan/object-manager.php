<?php

declare(strict_types=1);

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$metadataFactory = new class() implements ClassMetadataFactory {
    public function getAllMetadata(): array
    {
        return [];
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        throw new MappingException(sprintf('No metadata stub for %s', $className));
    }

    public function isTransient(string $className): bool
    {
        return true;
    }

    public function hasMetadataFor(string $className): bool
    {
        return false;
    }

    public function setMetadataFor(string $className, ClassMetadata $class): void
    {
    }
};

return new class($metadataFactory) implements ObjectManager {
    public function __construct(private readonly ClassMetadataFactory $metadataFactory)
    {
    }

    public function find(string $className, mixed $id): ?object
    {
        return null;
    }

    public function persist(object $object): void
    {
    }

    public function remove(object $object): void
    {
    }

    public function clear(): void
    {
    }

    public function detach(object $object): void
    {
    }

    public function refresh(object $object): void
    {
    }

    public function flush(): void
    {
    }

    public function getRepository(string $className): ObjectRepository
    {
        throw new MappingException(sprintf('No repository stub for %s', $className));
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->metadataFactory;
    }

    public function initializeObject(object $obj): void
    {
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return false;
    }

    public function contains(object $object): bool
    {
        return false;
    }
};
