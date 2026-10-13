<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\FormBundle\Entity\Form;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

#[ORM\Entity(repositoryClass: FormDoiActionRepository::class)]
#[ORM\Table(name: 'form_doi_actions')]
#[ORM\Index(columns: ['type'], name: 'form_doi_action_type_search')]
class FormDoiAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(name: 'action_order', type: 'integer')]
    private int $order = 0;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'array')]
    private array $properties = [];

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'form_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Form $form = null;

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('type', new Assert\NotBlank(message: 'mautic.core.name.required', groups: ['action']));
    }

    public function __clone()
    {
        $this->id   = null;
        $this->form = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function setOrder(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    /**
     * @param array<string, mixed> $properties
     */
    public function setProperties(array $properties): self
    {
        $this->properties = $properties;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setForm(Form $form): self
    {
        $this->form = $form;

        return $this;
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function convertToArray(): array
    {
        return get_object_vars($this);
    }
}
