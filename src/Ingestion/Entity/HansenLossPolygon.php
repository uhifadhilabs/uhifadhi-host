<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Repository\HansenLossPolygonRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * STAGING: one raw polygonized Hansen pixel-cluster (dn = lossyear byte value,
 * 1–23 ⇒ 2001–2023) awaiting the per-year dissolve. Rows live only for the
 * duration of one ingestion run — the pipeline empties the table before and
 * after. Doctrine-managed like every other table, so the whole pipeline stays
 * on the ORM write path.
 */
#[ORM\Entity(repositoryClass: HansenLossPolygonRepository::class)]
#[ORM\Table(name: 'hansen_loss_polygon')]
class HansenLossPolygon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $dn = null;

    #[ORM\Column(type: 'polygon')]
    private ?string $geom = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDn(): ?int
    {
        return $this->dn;
    }

    public function setDn(int $dn): static
    {
        $this->dn = $dn;

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
