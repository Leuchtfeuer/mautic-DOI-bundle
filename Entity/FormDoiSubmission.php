<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\LeadBundle\Entity\Lead;

#[ORM\Entity(repositoryClass: FormDoiSubmissionRepository::class)]
#[ORM\Table(name: 'form_doi_submissions')]
#[ORM\Index(columns: ['hash'], name: 'form_doi_submission_hash_search')]
#[ORM\Index(columns: ['status'], name: 'form_doi_submission_status_search')]
class FormDoiSubmission
{
    public const STATUS_PENDING              = 'pending';

    public const STATUS_CONFIRMED            = 'confirmed';

    public const STATUS_SKIPPED              = 'skipped';

    public const STATUS_TIMEOUT              = 'timeout';

    public const SKIP_REASON_COOKIE_MATCH    = 'cookie_match';

    public const SKIP_REASON_CONDITION_MATCH = 'condition_match';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Submission::class)]
    #[ORM\JoinColumn(name: 'form_submission_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Submission $formSubmission = null;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(name: 'form_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Form $form = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'submitted_consent_snapshot', type: 'json', nullable: true)]
    private ?array $submittedConsentSnapshot = null;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(name: 'lead_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Lead $lead = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $hash;

    #[ORM\Column(name: 'date_created', type: 'datetime')]
    private \DateTime $dateCreated;

    #[ORM\Column(name: 'date_confirmed', type: 'datetime', nullable: true)]
    private ?\DateTime $dateConfirmed = null;

    #[ORM\Column(name: 'date_followup_sent', type: 'datetime', nullable: true)]
    private ?\DateTime $dateFollowupSent = null;

    #[ORM\Column(name: 'date_timeout', type: 'datetime', nullable: true)]
    private ?\DateTime $dateTimeout = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'verification_skipped', type: 'boolean', options: ['default' => false])]
    private bool $verificationSkipped = false;

    #[ORM\Column(name: 'skip_reason', type: 'string', length: 50, nullable: true)]
    private ?string $skipReason = null;

    /**
     * A secure token used to verify ownership of a browser that completed this DOI.
     * This is only generated and set upon successful confirmation.
     */
    #[ORM\Column(name: 'browser_proof_token', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $browserProofToken = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setFormSubmission(Submission $formSubmission): self
    {
        $this->formSubmission = $formSubmission;

        return $this;
    }

    public function getFormSubmission(): ?Submission
    {
        return $this->formSubmission;
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

    /**
     * @param array<string, mixed>|null $submittedConsentSnapshot
     */
    public function setSubmittedConsentSnapshot(?array $submittedConsentSnapshot): self
    {
        $this->submittedConsentSnapshot = $submittedConsentSnapshot;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubmittedConsentSnapshot(): ?array
    {
        return $this->submittedConsentSnapshot;
    }

    public function setLead(?Lead $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setDateCreated(\DateTime $dateCreated): self
    {
        $this->dateCreated = $dateCreated;

        return $this;
    }

    public function getDateCreated(): \DateTime
    {
        return $this->dateCreated;
    }

    public function setDateConfirmed(?\DateTime $dateConfirmed): self
    {
        $this->dateConfirmed = $dateConfirmed;

        return $this;
    }

    public function getDateFollowupSent(): ?\DateTime
    {
        return $this->dateFollowupSent;
    }

    public function hasFollowupSent(): bool
    {
        return null !== $this->dateFollowupSent;
    }

    public function markFollowupSent(): self
    {
        $this->dateFollowupSent = new \DateTime();

        return $this;
    }

    public function getDateConfirmed(): ?\DateTime
    {
        return $this->dateConfirmed;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isConfirmed(): bool
    {
        return self::STATUS_CONFIRMED === $this->status;
    }

    public function confirm(): self
    {
        $this->status        = self::STATUS_CONFIRMED;
        $this->dateConfirmed = new \DateTime();

        return $this;
    }

    public function isVerificationSkipped(): bool
    {
        return $this->verificationSkipped;
    }

    public function setVerificationSkipped(bool $verificationSkipped): self
    {
        $this->verificationSkipped = $verificationSkipped;

        return $this;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function setSkipReason(?string $skipReason): self
    {
        $this->skipReason = $skipReason;

        return $this;
    }

    public function getBrowserProofToken(): ?string
    {
        return $this->browserProofToken;
    }

    public function setBrowserProofToken(?string $browserProofToken): self
    {
        $this->browserProofToken = $browserProofToken;

        return $this;
    }

    /**
     * Marks the submission as skipped and confirmed.
     */
    public function skip(string $reason): self
    {
        $this->status              = self::STATUS_SKIPPED;
        $this->dateConfirmed       = new \DateTime();
        $this->verificationSkipped = true;
        $this->skipReason          = $reason;

        return $this;
    }

    /**
     * Marks the submission as timed out.
     */
    public function timeout(): self
    {
        $this->status      = self::STATUS_TIMEOUT;
        $this->dateTimeout = new \DateTime();

        return $this;
    }

    public function getDateTimeout(): ?\DateTime
    {
        return $this->dateTimeout;
    }

    public function setDateTimeout(?\DateTime $dateTimeout): self
    {
        $this->dateTimeout = $dateTimeout;

        return $this;
    }

    public function isTimedOut(): bool
    {
        return self::STATUS_TIMEOUT === $this->status;
    }
}
