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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Seam\Entity\Module;

/**
 * An org-wide unit of the authority (Protection & Security, Ecology, Tourism, …) and the
 * {@see Module}s it works in. A department is a lens, never a fence: it re-orders what a member
 * sees so their work leads, and gates nothing — every module stays reachable by everyone, with
 * access decided by {@see \Uhifadhi\Enum\PermissionEnum} alone. Membership is indirect: a
 * {@see Position} sits in at most one department, and a {@see User} inherits it through theirs.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'department')]
#[ORM\HasLifecycleCallbacks]
class Department
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 120, unique: true)]
    private ?string $name = null;

    /**
     * The modules this department works in — the owning side, and now the ONLY
     * side, of `department_module`.
     *
     * A module belongs to uhifadhi/seam-module, which knows nothing about
     * departments and must not: a department is this application's lens over the
     * catalogue, invented here, and a runtime that carried the inverse collection
     * would be carrying a concept only its host has. The join table is unchanged
     * — it was always defined on this side.
     *
     * @var Collection<int, Module>
     */
    #[ORM\ManyToMany(targetEntity: Module::class)]
    #[ORM\JoinTable(name: 'department_module')]
    #[ORM\JoinColumn(name: 'department_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'module_id', onDelete: 'CASCADE')]
    private Collection $modules;

    public function __construct()
    {
        $this->modules = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, Module>
     */
    public function getModules(): Collection
    {
        return $this->modules;
    }

    public function hasModule(Module $module): bool
    {
        return $this->modules->contains($module);
    }

    public function addModule(Module $module): static
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
        }

        return $this;
    }

    public function removeModule(Module $module): static
    {
        $this->modules->removeElement($module);

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
