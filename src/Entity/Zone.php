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
use Uhifadhi\Repository\ZoneRepository;

/**
 * A named polygon subdividing one {@see AreaOfInterest} — the SPATIAL lens, the way a
 * {@see Department} is the organizational one: data an admin draws or uploads, never
 * code. Modules consume zones generically ("which zone is this point in?") and must
 * never name one; an org with no zones at all is the normal state, so every consumer
 * treats "unzoned" as a first-class answer rather than a missing configuration.
 *
 * Sibling zones may touch along an edge and may leave gaps, but never share interior —
 * the invariant lives in {@see \Uhifadhi\Service\ZoneService}, which is the only
 * supported way to create a zone or replace its geometry. Names are unique per area, so
 * two areas may each have a "North". `geom` is a MultiPolygon in WGS84 like the area's.
 */
#[ORM\Entity(repositoryClass: ZoneRepository::class)]
#[ORM\Table(name: 'zone')]
#[ORM\UniqueConstraint(name: 'uniq_zone_area_name', columns: ['area_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Zone
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    /** The zone lives and dies with its area — the FK cascades in the database. */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    #[ORM\Column(type: 'multipolygon')]
    private ?string $geom = null;

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

    public function getArea(): ?AreaOfInterest
    {
        return $this->area;
    }

    public function setArea(AreaOfInterest $area): static
    {
        $this->area = $area;

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

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
