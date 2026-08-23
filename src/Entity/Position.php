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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Entity\Trait\TimestampableTrait;
use Uhifadhi\Entity\Trait\UuidTrait;
use Uhifadhi\Enum\PermissionEnum;
use Uhifadhi\Repository\PositionRepository;

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
        return $this->hasPermissionValue($permission->value);
    }

    /**
     * The raw granted values. The enum-typed accessors above silently drop any
     * value that is not a {@see PermissionEnum} case, so module-declared
     * permissions (validated against the catalogue on write) only round-trip
     * through this value-based surface.
     *
     * @return list<string>
     */
    public function getPermissionValues(): array
    {
        return $this->permissions;
    }

    /**
     * @param list<string> $values catalogue-validated by the caller
     *                             (see PermissionCatalogueService::knownValues)
     */
    public function setPermissionValues(array $values): static
    {
        $this->permissions = array_values(array_unique($values));

        return $this;
    }

    public function hasPermissionValue(string $value): bool
    {
        return \in_array($value, $this->permissions, true);
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
