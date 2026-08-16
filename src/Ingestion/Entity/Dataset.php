<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Foundation\Entity\Trait\TimestampableTrait;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The generic, per-module data store a visualization binds to — one row per (area, module slug,
 * dataset key), so a module owns its data without a bespoke topic table. Tabular kinds keep their
 * payload inline (columns + rows); spatial kinds keep a file path on the shared engine volume. An
 * ingestion re-run overwrites the same row (see {@see DatasetRepository::upsert()}). The producing
 * {@see DatasetRun} is kept for provenance and survives its data being replaced.
 */
#[ORM\Entity(repositoryClass: DatasetRepository::class)]
#[ORM\Table(name: 'module_dataset')]
#[ORM\UniqueConstraint(name: 'uniq_dataset_area_module_key', columns: ['area_id', 'module_slug', 'dataset_key'])]
#[ORM\HasLifecycleCallbacks]
class Dataset
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The data belongs to its area: it dies with it (CASCADE), unlike the provenance run (SET NULL).
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    #[ORM\Column(length: 64)]
    private ?string $moduleSlug = null;

    #[ORM\Column(name: 'dataset_key', length: 64)]
    private ?string $key = null;

    #[ORM\Column(length: 16, enumType: DatasetKind::class)]
    private DatasetKind $kind = DatasetKind::Series;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $columns = null;

    /** @var list<list<scalar|null>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rows = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $path = null;

    /** Inline binary payload, base64 (e.g. a raster PNG) — served by the module layer routes. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    /**
     * Payload metadata (e.g. {format: png, bounds: [[s,w],[n,e]]} for a raster overlay).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(length: 128)]
    private string $source = '';

    #[ORM\ManyToOne(targetEntity: DatasetRun::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DatasetRun $run = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): ?AreaOfInterest
    {
        return $this->area;
    }

    public function setArea(AreaOfInterest $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getModuleSlug(): ?string
    {
        return $this->moduleSlug;
    }

    public function setModuleSlug(string $moduleSlug): static
    {
        $this->moduleSlug = $moduleSlug;

        return $this;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getKind(): DatasetKind
    {
        return $this->kind;
    }

    public function setKind(DatasetKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return list<string>|null */
    public function getColumns(): ?array
    {
        return $this->columns;
    }

    /** @param list<string>|null $columns */
    public function setColumns(?array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /** @return list<list<scalar|null>>|null */
    public function getRows(): ?array
    {
        return $this->rows;
    }

    /** @param list<list<scalar|null>>|null $rows */
    public function setRows(?array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(?string $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /** @param array<string, mixed>|null $meta */
    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getRun(): ?DatasetRun
    {
        return $this->run;
    }

    public function setRun(?DatasetRun $run): static
    {
        $this->run = $run;

        return $this;
    }
}
