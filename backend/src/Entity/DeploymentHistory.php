<?php

namespace App\Entity;

use App\Repository\DeploymentHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeploymentHistoryRepository::class)]
#[ORM\Table(name: 'deployment_history')]
class DeploymentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private ?string $commitHash = null;

    #[ORM\Column(length: 255)]
    private ?string $branch = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $repository = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $githubRunId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $deployedAt = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null; // 'success', 'failed', 'rolled_back'

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deploymentSteps = null; // JSON of deployment steps

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $duration = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deployedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rollbackReason = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $rolledBackAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $rolledBackToCommit = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommitHash(): ?string
    {
        return $this->commitHash;
    }

    public function setCommitHash(string $commitHash): static
    {
        $this->commitHash = $commitHash;
        return $this;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function setBranch(string $branch): static
    {
        $this->branch = $branch;
        return $this;
    }

    public function getRepository(): ?string
    {
        return $this->repository;
    }

    public function setRepository(?string $repository): static
    {
        $this->repository = $repository;
        return $this;
    }

    public function getGithubRunId(): ?string
    {
        return $this->githubRunId;
    }

    public function setGithubRunId(?string $githubRunId): static
    {
        $this->githubRunId = $githubRunId;
        return $this;
    }

    public function getDeployedAt(): ?\DateTimeInterface
    {
        return $this->deployedAt;
    }

    public function setDeployedAt(\DateTimeInterface $deployedAt): static
    {
        $this->deployedAt = $deployedAt;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDeploymentSteps(): ?string
    {
        return $this->deploymentSteps;
    }

    public function setDeploymentSteps(?string $deploymentSteps): static
    {
        $this->deploymentSteps = $deploymentSteps;
        return $this;
    }

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function setDuration(?string $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getDeployedBy(): ?string
    {
        return $this->deployedBy;
    }

    public function setDeployedBy(?string $deployedBy): static
    {
        $this->deployedBy = $deployedBy;
        return $this;
    }

    public function getRollbackReason(): ?string
    {
        return $this->rollbackReason;
    }

    public function setRollbackReason(?string $rollbackReason): static
    {
        $this->rollbackReason = $rollbackReason;
        return $this;
    }

    public function getRolledBackAt(): ?\DateTimeInterface
    {
        return $this->rolledBackAt;
    }

    public function setRolledBackAt(?\DateTimeInterface $rolledBackAt): static
    {
        $this->rolledBackAt = $rolledBackAt;
        return $this;
    }

    public function getRolledBackToCommit(): ?string
    {
        return $this->rolledBackToCommit;
    }

    public function setRolledBackToCommit(?string $rolledBackToCommit): static
    {
        $this->rolledBackToCommit = $rolledBackToCommit;
        return $this;
    }

    public function getDeploymentStepsArray(): array
    {
        return $this->deploymentSteps ? json_decode($this->deploymentSteps, true) : [];
    }

    public function setDeploymentStepsArray(array $steps): static
    {
        $this->deploymentSteps = json_encode($steps);
        return $this;
    }

    public function getShortCommitHash(): string
    {
        return substr($this->commitHash ?? '', 0, 7);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function isRolledBack(): bool
    {
        return $this->status === 'rolled_back';
    }
} 