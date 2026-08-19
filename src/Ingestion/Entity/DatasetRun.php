<?php

declare(strict_types=1);

namespace Uhifadhi\Ingestion\Entity;

use Uhifadhi\Ingestion\Repository\DatasetRunRepository;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Provenance for one ingestion run: which dataset, with which parameters, when,
 * and what it produced — so every row in a topic table can be traced to the run
 * that wrote it.
 */
#[ORM\Entity(repositoryClass: DatasetRunRepository::class)]
#[ORM\Table(name: 'dataset_run')]
class DatasetRun
{
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_SUCCEEDED = 'succeeded';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $dataset = null;

    // Nullable: future datasets may not be AOI-scoped (e.g. global layers); the
    // run record must outlive a deleted area (SET NULL), unlike the data rows.
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AreaOfInterest $aoi = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_RUNNING;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $params = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $report = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataset(): ?string
    {
        return $this->dataset;
    }

    public function getAoi(): ?AreaOfInterest
    {
        return $this->aoi;
    }

    public function setAoi(?AreaOfInterest $aoi): static
    {
        $this->aoi = $aoi;

        return $this;
    }

    public function setDataset(string $dataset): static
    {
        $this->dataset = $dataset;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<string, mixed> $params */
    public function setParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getReport(): ?array
    {
        return $this->report;
    }

    /** @param array<string, mixed>|null $report */
    public function setReport(?array $report): static
    {
        $this->report = $report;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }
}
