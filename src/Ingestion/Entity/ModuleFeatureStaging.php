<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Repository\ModuleFeatureStagingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * STAGING: one raw polygon a module's engine handed over (labelled by an attribute — e.g. a
 * land-cover class), awaiting the per-label dissolve into {@see ModuleFeature}. The generic vector
 * analogue of a per-source staging table: rows live only for one ingestion run, emptied before and
 * after, so the whole pipeline stays on the ORM write path and PostGIS does the geometry work.
 */
#[ORM\Entity(repositoryClass: ModuleFeatureStagingRepository::class)]
#[ORM\Table(name: 'module_feature_staging')]
class ModuleFeatureStaging
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $label = null;

    #[ORM\Column(type: 'polygon')]
    private ?string $geom = null;

    public function getId(): ?int
    {
        return $this->id;
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
