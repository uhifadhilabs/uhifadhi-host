<?php

declare(strict_types=1);

namespace App\Spatial\Entity;

use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named area of interest — a study/clip boundary in the shared Spatial kernel (the
 * NCA boundary and, later, buffers). `geom` is a MultiPolygon in WGS84, exchanged
 * as GeoJSON via the fundi-postgis geometry type.
 */
#[ORM\Entity(repositoryClass: AreaOfInterestRepository::class)]
#[ORM\Table(name: 'area_of_interest')]
class AreaOfInterest
{
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

    public function getId(): ?int
    {
        return $this->id;
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
