<?php

declare(strict_types=1);

namespace App\Access\Entity;

use App\Access\Enum\PermissionEnum;
use App\Access\Repository\PositionRepository;
use App\Foundation\Entity\Trait\TimestampableTrait;
use App\Foundation\Entity\Trait\UuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named position that bundles a set of granular {@see PermissionEnum}s. An Admin defines
 * positions and ticks their permissions; every Staff user assigned a position inherits them.
 * Super Admin / Admin / Manager ignore positions — they hold everything by tier — so no
 * position is ever "locked" in practice; {@see $locked} is reserved for that future need.
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Position
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * The granted permissions, stored as {@see PermissionEnum} values.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /** Reserved: a position whose label is fixed. Owners bypass positions by tier, so unused today. */
    #[ORM\Column]
    private bool $locked = false;

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
     * @return list<PermissionEnum>
     */
    public function getPermissions(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?PermissionEnum => PermissionEnum::tryFrom($value),
            $this->permissions,
        )));
    }

    /**
     * @param list<PermissionEnum> $permissions
     */
    public function setPermissions(array $permissions): static
    {
        $this->permissions = array_values(array_unique(array_map(
            static fn (PermissionEnum $permission): string => $permission->value,
            $permissions,
        )));

        return $this;
    }

    public function hasPermission(PermissionEnum $permission): bool
    {
        return \in_array($permission->value, $this->permissions, true);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
