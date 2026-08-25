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
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Repository\DepartmentGoalRepository;

/**
 * A commitment a {@see Department} DECLARED, scored against one module KPI.
 *
 * This is the one thing on a performance surface the platform does not derive: every figure
 * there is an attached module's, but a target is a human statement about what should happen, and
 * nothing in the schema could infer it. Hence the design's own line — "a goal is the
 * department's own declaration, not a number the platform invents".
 *
 * A goal names a {@see $kpiRef} rather than holding a figure, so it is scored from whatever the
 * module reports today. If the module that computes that KPI is not attached (or not installed),
 * the goal is AWAITING — drawn as an honest empty slot, never as 0% attained.
 *
 * The OWNING POSITION, not a person: positions are how this app expresses accountability, and a
 * person who leaves does not take the commitment with them. It is nullable and SET NULL for the
 * same reason a department's deletion unfiles its positions — a goal outlives an org change.
 */
#[ORM\Entity(repositoryClass: DepartmentGoalRepository::class)]
#[ORM\Table(name: 'department_goal')]
#[ORM\HasLifecycleCallbacks]
class DepartmentGoal
{
    use TimestampableTrait;
    use UuidTrait;

    /** The goal is being met. */
    public const string MET = 'met';

    /** Measurable, and short of target. */
    public const string AT_RISK = 'atrisk';

    /** Measurable, and far enough short that an exec should act. */
    public const string OFF_TRACK = 'offtrack';

    /** Not measurable at all: no module reports this KPI for this department. */
    public const string AWAITING = 'awaiting';

    /** Below this share of target a goal stops being "at risk" and starts being "off track". */
    private const float OFF_TRACK_BELOW = 0.75;

    /** The reporting windows a goal may be declared over — the period picker's own vocabulary. */
    public const array PERIODS = ['week', 'month', 'quarter', 'year'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * UNIDIRECTIONAL on purpose: Department stays a plain record of name + attached modules, and
     * goals are read through {@see DepartmentGoalRepository} where the surface needs them. The
     * CASCADE is the honest half of the delete consequence list — deleting a department really
     * does take its own declarations with it, and takes nothing else anywhere.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Department $department = null;

    /** What the department committed to, in its own words ("Coverage ≥ 60% each month"). */
    #[ORM\Column(length: 240)]
    private ?string $statement = null;

    /** The number to reach. Always "at least this much": every KPI on this seam is better larger. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $targetValue = 0.0;

    /** '' for a bare count, 'km', '%' — the same vocabulary {@see DepartmentKpi::$unit} uses. */
    #[ORM\Column(length: 24)]
    private string $targetUnit = '';

    /** The {@see DepartmentKpi::$key} this goal is scored from. Not a foreign key: modules come and go. */
    #[ORM\Column(length: 60)]
    private ?string $kpiRef = null;

    /** Who is accountable. A POSITION, so accountability survives the person holding it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Position $owningPosition = null;

    /** One of {@see self::PERIODS}: the window the target is stated per. */
    #[ORM\Column(length: 20)]
    private string $period = 'month';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getStatement(): ?string
    {
        return $this->statement;
    }

    public function setStatement(string $statement): static
    {
        $this->statement = $statement;

        return $this;
    }

    public function getTargetValue(): float
    {
        return $this->targetValue;
    }

    public function setTargetValue(float $targetValue): static
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function getTargetUnit(): string
    {
        return $this->targetUnit;
    }

    public function setTargetUnit(string $targetUnit): static
    {
        $this->targetUnit = $targetUnit;

        return $this;
    }

    public function getKpiRef(): ?string
    {
        return $this->kpiRef;
    }

    public function setKpiRef(string $kpiRef): static
    {
        $this->kpiRef = $kpiRef;

        return $this;
    }

    public function getOwningPosition(): ?Position
    {
        return $this->owningPosition;
    }

    public function setOwningPosition(?Position $owningPosition): static
    {
        $this->owningPosition = $owningPosition;

        return $this;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function setPeriod(string $period): static
    {
        if (!\in_array($period, self::PERIODS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a reporting period; expected one of %s.', $period, implode(', ', self::PERIODS)));
        }
        $this->period = $period;

        return $this;
    }

    /** The target as a card prints it ("≥ 2,000 km", "≥ 60%"). */
    public function targetLabel(): string
    {
        return \sprintf(
            "\u{2265} %s%s",
            number_format($this->targetValue, 0, '.', ','),
            '' === $this->targetUnit ? '' : ('%' === $this->targetUnit ? '%' : ' '.$this->targetUnit),
        );
    }

    /**
     * How far along this goal is, 0.0–1.0, or NULL when it cannot be measured.
     *
     * Null is the whole point of the type: a goal whose module is not attached is AWAITING, and
     * filling that slot with 0% would accuse a department of failing at something nobody asked
     * a module to compute.
     */
    public function attainment(?DepartmentKpi $kpi): ?float
    {
        if (null === $kpi || !$kpi->isKnown() || 0.0 >= $this->targetValue) {
            return null;
        }

        return min(1.0, (float) $kpi->value / $this->targetValue);
    }

    /** One of the four state constants — what the stance strip counts and the chip says. */
    public function state(?DepartmentKpi $kpi): string
    {
        $attainment = $this->attainment($kpi);
        if (null === $attainment) {
            return self::AWAITING;
        }
        if ($attainment >= 1.0) {
            return self::MET;
        }

        return $attainment < self::OFF_TRACK_BELOW ? self::OFF_TRACK : self::AT_RISK;
    }

    /** The chip's words for {@see state()}. */
    public function stateLabel(?DepartmentKpi $kpi): string
    {
        return match ($this->state($kpi)) {
            self::MET => 'Met',
            self::AT_RISK => 'At risk',
            self::OFF_TRACK => 'Off track',
            default => 'Awaiting module',
        };
    }

    public function __toString(): string
    {
        return $this->statement ?? '';
    }
}
