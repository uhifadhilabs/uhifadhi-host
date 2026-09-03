<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;
use Uhifadhi\Repository\AreaOfInterestRepository;
use UhifadhiLabs\Trunk\Area\AreaInterface;

/**
 * THE TRUNK'S AREA, RESOLVED. The module seam runtime owns the record of which
 * modules an area has switched on, and maps that association to its own
 * {@see AreaInterface}; config/packages/trunk.yaml resolves the interface to
 * this class. That is what lets a bundle hold a per-area table without
 * defining — or requiring — this application's area model.
 *
 * A named area of interest — a study/clip boundary in the shared Spatial kernel (the
 * NCA boundary and, later, buffers). `geom` is a MultiPolygon in WGS84, exchanged
 * as GeoJSON via the fundi-postgis geometry type. Addressed publicly by UUID (URLs
 * never expose the sequential id).
 */
#[ORM\Entity(repositoryClass: AreaOfInterestRepository::class)]
#[ORM\Table(name: 'area_of_interest')]
#[ORM\HasLifecycleCallbacks]
class AreaOfInterest implements AreaInterface
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

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
