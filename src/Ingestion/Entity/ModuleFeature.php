<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Repository\ModuleFeatureRepository;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\Mapping as ORM;

/**
 * A module's dissolved spatial layer for the map: one MultiPolygon per label (e.g. one per land-cover
 * class), per area + module + dataset key. The generic, per-module analogue of {@see \App\Forest\Entity\ForestLossYear}
 * — written by the spatial ingest (PostGIS ST_Union/ST_SimplifyPreserveTopology per label) and served
 * as GeoJSON to the Leaflet map. `geom` is a MultiPolygon in WGS84, exchanged as GeoJSON.
 */
#[ORM\Entity(repositoryClass: ModuleFeatureRepository::class)]
#[ORM\Table(name: 'module_feature')]
#[ORM\Index(name: 'idx_module_feature_lookup', columns: ['aoi_id', 'module_slug', 'dataset_key'])]
class ModuleFeature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $aoi = null;

    #[ORM\Column(length: 64)]
    private ?string $moduleSlug = null;

    #[ORM\Column(length: 64)]
    private ?string $datasetKey = null;

    #[ORM\Column(length: 64)]
    private ?string $label = null;

    #[ORM\Column(type: 'multipolygon')]
    private ?string $geom = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAoi(): ?AreaOfInterest
    {
        return $this->aoi;
    }

    public function setAoi(AreaOfInterest $aoi): static
    {
        $this->aoi = $aoi;

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

    public function getDatasetKey(): ?string
    {
        return $this->datasetKey;
    }

    public function setDatasetKey(string $datasetKey): static
    {
        $this->datasetKey = $datasetKey;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

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
}
