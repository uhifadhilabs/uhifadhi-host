<?php

declare(strict_types=1);

namespace App\Composition\Entity;

use App\Composition\Enum\VizType;
use App\Composition\Repository\VisualizationRepository;
use App\Foundation\Entity\Trait\TimestampableTrait;
use App\Foundation\Entity\Trait\UuidTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configured chart on an area's module — one card in the module's visualization grid. It plots the
 * module's variables (xAxis vs yAxis) as a {@see VizType}, optionally coloured by a dimension and
 * aggregated. Editing these (title, type, axes, colour, aggregation) is the "configure visualization"
 * modal; their order is drag-set within the module.
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

    #[ORM\Column(length: 40)]
    private ?string $xAxis = null;

    #[ORM\Column(length: 40)]
    private ?string $yAxis = null;

    /** The dimension the marks are coloured by, or null for a single series. */
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
