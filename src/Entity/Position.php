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
 *
 * A POSITION IS DEPARTMENT-SCOPED. Its name is unique INSIDE its department and nowhere else:
 * Ecology and Protection Service may each own an "Analyst", and the two share a word and
 * nothing at all besides — different permissions, different holders, different rows. An
 * org-wide unique name would have forced one of those two units to rename a job it has always
 * had, which is why every surface writes a position department-first ("Ecology / Analyst")
 * wherever the department is not already stated by context.
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'position')]
// Unique per (department, name) — but Postgres treats NULLs as distinct, so a single
// two-column index would quietly stop guarding the UNFILED positions, which are exactly the
// ones most likely to collide (everything created before departments existed still sits
// there). It therefore takes TWO partial unique indexes covering the two disjoint cases, the
// same shape WidgetPreference uses for its org-wide/area-scoped rows. Declared with `fields`
// so the host's naming strategy still owns the column names; the WHERE clauses name the
// column because that is what Postgres reads.
#[ORM\UniqueConstraint(
    name: 'uniq_position_department_name',
    fields: ['department', 'name'],
    options: ['where' => '(department_id IS NOT NULL)'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_position_unfiled_name',
    fields: ['name'],
    options: ['where' => '(department_id IS NULL)'],
)]
#[ORM\HasLifecycleCallbacks]
class Position
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

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

    /**
     * The department this position sits in, if any — how a user's department is derived. Purely
     * organizational: it re-orders what its holders see and grants nothing (permissions above do).
     *
     * It also SCOPES THE NAME (see the class banner). Nullable is a transition state, not a
     * department: "Unfiled" is a holding pen for positions that predate departments, it sorts
     * last, is drawn dashed everywhere, and its one real action is being filed under a real
     * department — holders and all.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

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

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

        return $this;
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
