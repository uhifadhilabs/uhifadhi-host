<?php

declare(strict_types=1);

namespace App\Spatial\Entity;

use App\Foundation\Entity\Trait\TimestampableTrait;
use App\Foundation\Entity\Trait\UuidTrait;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named area of interest — a study/clip boundary in the shared Spatial kernel (the
 * NCA boundary and, later, buffers). `geom` is a MultiPolygon in WGS84, exchanged
 * as GeoJSON via the fundi-postgis geometry type. Addressed publicly by UUID (URLs
 * never expose the sequential id).
 */
#[ORM\Entity(repositoryClass: AreaOfInterestRepository::class)]
#[ORM\Table(name: 'area_of_interest')]
#[ORM\HasLifecycleCallbacks]
class AreaOfInterest
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(type: 'multipolygon')]
    private ?string $geom = null;

    #[ORM\Column(length: 64)]
    private ?string $source = null;

    /** IUCN protected-area category (e.g. "II", "VI") — from the WDPA record. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $iucnCategory = null;

    /** Year the area was established/gazetted — from the WDPA record. */
    #[ORM\Column(nullable: true)]
    private ?int $establishedYear = null;

    /** Tree-cover percentage of the area (Hansen tree-cover 2000, when ingested). */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $treeCoverPct = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIucnCategory(): ?string
    {
        return $this->iucnCategory;
    }

    public function setIucnCategory(?string $iucnCategory): static
    {
        $this->iucnCategory = $iucnCategory;

        return $this;
    }

    public function getEstablishedYear(): ?int
    {
        return $this->establishedYear;
    }

    public function setEstablishedYear(?int $establishedYear): static
    {
        $this->establishedYear = $establishedYear;

        return $this;
    }

    public function getTreeCoverPct(): ?float
    {
        return $this->treeCoverPct;
    }

    public function setTreeCoverPct(?float $treeCoverPct): static
    {
        $this->treeCoverPct = $treeCoverPct;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(string $geom): static
    {
        $this->geom = $geom;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }
}
