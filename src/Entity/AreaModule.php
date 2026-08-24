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
use Uhifadhi\Repository\AreaModuleRepository;

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
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

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

    public function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
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
