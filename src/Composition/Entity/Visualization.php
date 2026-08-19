<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Entity;

use Uhifadhi\Composition\Enum\VizType;
use Uhifadhi\Composition\Repository\VisualizationRepository;
use Uhifadhi\Foundation\Entity\Trait\TimestampableTrait;
use Uhifadhi\Foundation\Entity\Trait\UuidTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configured chart on an area's module — one card in the module's visualization grid. It binds to a
 * dataset in the module's data store (by {@see $datasetKey}) and plots two of its columns (xAxis vs
 * yAxis) as a {@see VizType}, optionally coloured by a dimension and aggregated. A viz with no
 * datasetKey is unbound (nothing to plot yet). Editing these (title, type, dataset, columns, colour,
 * aggregation) is the "configure visualization" modal; their order is drag-set within the module.
 */
#[ORM\Entity(repositoryClass: VisualizationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Visualization
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'visualizations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AreaModule $areaModule = null;

    #[ORM\Column(length: 80)]
    private ?string $title = null;

    #[ORM\Column(enumType: VizType::class)]
    private VizType $type = VizType::Bar;

    /** The dataset (by key, within the module's data store) this chart plots; null until configured. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $datasetKey = null;

    /** The dataset column plotted on the x-axis. */
    #[ORM\Column(length: 40)]
    private ?string $xAxis = null;

    /** The dataset column plotted on the y-axis. */
    #[ORM\Column(length: 40)]
    private ?string $yAxis = null;

    /** The dataset column the marks are coloured by, or null for a single series. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $colourBy = null;

    /** Sum | Mean | Max | None. */
    #[ORM\Column(length: 20)]
    private string $aggregation = 'None';

    /** Order within the module's visualization grid. */
    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAreaModule(): ?AreaModule
    {
        return $this->areaModule;
    }

    public function setAreaModule(AreaModule $areaModule): static
    {
        $this->areaModule = $areaModule;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getType(): VizType
    {
        return $this->type;
    }

    public function setType(VizType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDatasetKey(): ?string
    {
        return $this->datasetKey;
    }

    public function setDatasetKey(?string $datasetKey): static
    {
        $this->datasetKey = $datasetKey;

        return $this;
    }

    /** Whether this viz is wired to a dataset to plot (an unbound viz renders nothing). */
    public function isBound(): bool
    {
        return null !== $this->datasetKey && '' !== $this->datasetKey;
    }

    public function getXAxis(): ?string
    {
        return $this->xAxis;
    }

    public function setXAxis(string $xAxis): static
    {
        $this->xAxis = $xAxis;

        return $this;
    }

    public function getYAxis(): ?string
    {
        return $this->yAxis;
    }

    public function setYAxis(string $yAxis): static
    {
        $this->yAxis = $yAxis;

        return $this;
    }

    public function getColourBy(): ?string
    {
        return $this->colourBy;
    }

    public function setColourBy(?string $colourBy): static
    {
        $this->colourBy = $colourBy;

        return $this;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    public function setAggregation(string $aggregation): static
    {
        $this->aggregation = $aggregation;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
