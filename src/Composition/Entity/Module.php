<?php

declare(strict_types=1);

namespace App\Composition\Entity;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Repository\ModuleRepository;
use App\Foundation\Entity\Trait\TimestampableTrait;
use App\Foundation\Entity\Trait\UuidTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A module in the catalogue — one analytical lens an area's sub-app can carry (Forest loss,
 * Vegetation, Roads, …). This is the definition, shared across areas; whether it is switched on
 * for a given area is an {@see AreaModule}. The Overview module is {@see $pinned} — always on,
 * never removable.
 */
#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Module
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Routing + identity key, e.g. "forest" → /areas/{uuid}/forest. */
    #[ORM\Column(length: 40, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 80)]
    private ?string $name = null;

    #[ORM\Column(enumType: ModuleCategory::class)]
    private ModuleCategory $category = ModuleCategory::Flux;

    #[ORM\Column(enumType: ModuleStatus::class)]
    private ModuleStatus $status = ModuleStatus::Template;

    /** The upstream data the module draws on, shown as a mono pill (e.g. "Hansen GFC"). */
    #[ORM\Column(length: 80)]
    private ?string $dataSource = null;

    /** The Overview hub: always on an area, never reorderable or removable. */
    #[ORM\Column]
    private bool $pinned = false;

    /** Catalogue display order (the order new areas receive modules in). */
    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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

    public function getCategory(): ModuleCategory
    {
        return $this->category;
    }

    public function setCategory(ModuleCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getStatus(): ModuleStatus
    {
        return $this->status;
    }

    public function setStatus(ModuleStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDataSource(): ?string
    {
        return $this->dataSource;
    }

    public function setDataSource(string $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

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

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
