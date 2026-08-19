<?php

declare(strict_types=1);

namespace Uhifadhi\Composition\Entity;

use Uhifadhi\Composition\Repository\AreaModuleRepository;
use Uhifadhi\Foundation\Entity\Trait\TimestampableTrait;
use Uhifadhi\Foundation\Entity\Trait\UuidTrait;
use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A {@see Module} switched on for one {@see AreaOfInterest}, at a given position in that area's
 * sub-nav. Toggling it off keeps the row (and any ingested data) but drops it from the area's
 * sub-app — "its data stays, it just leaves the area". The pinned Overview module is always
 * active and never reorderable.
 */
#[ORM\Entity(repositoryClass: AreaModuleRepository::class)]
#[ORM\Table(name: 'area_module')]
#[ORM\UniqueConstraint(name: 'uniq_area_module', columns: ['aoi_id', 'module_id'])]
#[ORM\HasLifecycleCallbacks]
class AreaModule
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'aoi_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'module_id', nullable: false, onDelete: 'CASCADE')]
    private ?Module $module = null;

    /** On the area's sub-nav (true) or parked in the "add a module" shop (false). */
    #[ORM\Column]
    private bool $active = true;

    /** Order within the area's sub-nav; the pinned Overview always leads. */
    #[ORM\Column]
    private int $position = 0;

    /** Whether this module's default visualizations have been seeded once — so deleting them all
     *  doesn't resurrect them on the next view. */
    #[ORM\Column]
    private bool $vizSeeded = false;

    /** @var Collection<int, Visualization> */
    #[ORM\OneToMany(mappedBy: 'areaModule', targetEntity: Visualization::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $visualizations;

    public function __construct()
    {
        $this->visualizations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Visualization>
     */
    public function getVisualizations(): Collection
    {
        return $this->visualizations;
    }

    /** Keeps both sides of the association in sync — same-request reads see the new viz immediately. */
    public function addVisualization(Visualization $visualization): static
    {
        if (!$this->visualizations->contains($visualization)) {
            $this->visualizations->add($visualization);
            $visualization->setAreaModule($this);
        }

        return $this;
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

    public function getModule(): ?Module
    {
        return $this->module;
    }

    public function setModule(Module $module): static
    {
        $this->module = $module;

        return $this;
    }

    public function isVizSeeded(): bool
    {
        return $this->vizSeeded;
    }

    public function setVizSeeded(bool $vizSeeded): static
    {
        $this->vizSeeded = $vizSeeded;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

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
