<?php

declare(strict_types=1);

namespace App\Forest\Entity;

use App\Forest\Repository\ForestLossYearRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One year's forest-loss footprint (Hansen Global Forest Change), clipped to the
 * NCA area of interest. `geom` is a MultiPolygon in WGS84, exchanged as GeoJSON.
 */
#[ORM\Entity(repositoryClass: ForestLossYearRepository::class)]
#[ORM\Table(name: 'forest_loss_year')]
class ForestLossYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $year = null;

    #[ORM\Column(type: 'multipolygon')]
    private ?string $geom = null;

    #[ORM\Column(type: 'float')]
    private ?float $areaHa = null;

    #[ORM\Column(length: 64)]
    private ?string $source = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

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

    public function getAreaHa(): ?float
    {
        return $this->areaHa;
    }

    public function setAreaHa(float $areaHa): static
    {
        $this->areaHa = $areaHa;

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
