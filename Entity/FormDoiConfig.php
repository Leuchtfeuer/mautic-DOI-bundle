<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\EmailBundle\Entity\Email;
use Mautic\FormBundle\Entity\Form;

#[ORM\Entity(repositoryClass: FormDoiConfigRepository::class)]
#[ORM\Table(name: 'form_doi_config')]
#[ORM\UniqueConstraint(name: 'form_id_unique', columns: ['form_id'])]
#[ORM\HasLifecycleCallbacks]
class FormDoiConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'form_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Form $form = null;

    #[ORM\ManyToOne(targetEntity: Email::class)]
    #[ORM\JoinColumn(name: 'verification_email_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Email $verificationEmail = null;

    #[ORM\ManyToOne(targetEntity: Email::class)]
    #[ORM\JoinColumn(name: 'follow_up_email_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Email $followUpEmail = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $successRedirectUrl = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $errorRedirectUrl = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = false;

    /**
     * @var array<mixed>|null
     */
    #[ORM\Column(name: 'skip_conditions', type: 'json', nullable: true)]
    private ?array $skipConditions = null;

    #[ORM\Column(name: 'skip_on_cookie', type: 'boolean', options: ['default' => false])]
    private bool $skipOnCookie = false;

    #[ORM\Column(name: 'skip_post_action', type: 'string', length: 255, nullable: true)]
    private ?string $skipPostAction = null;

    #[ORM\Column(name: 'skip_post_action_property', type: 'text', nullable: true)]
    private ?string $skipPostActionProperty = null;

    #[ORM\Column(name: 'delete_after_timeout', type: 'boolean', options: ['default' => false])]
    private bool $deleteAfterTimeout = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdated(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function __clone(): void
    {
        $this->id        = null;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->form      = null;
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

    public function setForm(Form $form): self
    {
        $this->form = $form;

        return $this;
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function setVerificationEmail(?Email $email): self
    {
        $this->verificationEmail = $email;

        return $this;
    }

    public function getVerificationEmail(): ?Email
    {
        return $this->verificationEmail;
    }

    public function setFollowUpEmail(?Email $email): self
    {
        $this->followUpEmail = $email;

        return $this;
    }

    public function getFollowUpEmail(): ?Email
    {
        return $this->followUpEmail;
    }

    public function setSuccessRedirectUrl(?string $url): self
    {
        $this->successRedirectUrl = $url;

        return $this;
    }

    public function getSuccessRedirectUrl(): ?string
    {
        return $this->successRedirectUrl;
    }

    public function setErrorRedirectUrl(?string $url): self
    {
        $this->errorRedirectUrl = $url;

        return $this;
    }

    public function getErrorRedirectUrl(): ?string
    {
        return $this->errorRedirectUrl;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<mixed>|null
     */
    public function getSkipConditions(): ?array
    {
        return $this->skipConditions;
    }

    /**
     * @param array<mixed>|null $skipConditions
     */
    public function setSkipConditions(?array $skipConditions): self
    {
        $this->skipConditions = $skipConditions;

        return $this;
    }

    public function isSkipOnCookie(): bool
    {
        return $this->skipOnCookie;
    }

    public function setSkipOnCookie(bool $skipOnCookie): self
    {
        $this->skipOnCookie = $skipOnCookie;

        return $this;
    }

    public function getSkipPostAction(): ?string
    {
        return $this->skipPostAction;
    }

    public function setSkipPostAction(?string $skipPostAction): self
    {
        $this->skipPostAction = $skipPostAction;

        return $this;
    }

    public function getSkipPostActionProperty(): ?string
    {
        return $this->skipPostActionProperty;
    }

    public function setSkipPostActionProperty(?string $skipPostActionProperty): self
    {
        $this->skipPostActionProperty = $skipPostActionProperty;

        return $this;
    }

    public function isDeleteAfterTimeout(): bool
    {
        return $this->deleteAfterTimeout;
    }

    public function setDeleteAfterTimeout(bool $deleteAfterTimeout): self
    {
        $this->deleteAfterTimeout = $deleteAfterTimeout;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function convertToArray(): array
    {
        return get_object_vars($this);
    }
}
