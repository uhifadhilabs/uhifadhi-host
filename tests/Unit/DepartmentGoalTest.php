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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Entity\DepartmentGoal;
use Uhifadhi\Module\DepartmentKpi;

/**
 * Scoring a declaration against a module's figure — including the case the model canon cares
 * most about: a goal nobody can measure is AWAITING, and must never be drawn as 0% attained.
 */
final class DepartmentGoalTest extends TestCase
{
    public function testAGoalAtOrAboveTargetIsMet(): void
    {
        $goal = self::goal('coverage', 60.0, '%');
        $kpi = self::kpi('coverage', 71.0, '%');

        self::assertSame(1.0, $goal->attainment($kpi));
        self::assertSame(DepartmentGoal::MET, $goal->state($kpi));
        self::assertSame('Met', $goal->stateLabel($kpi));
    }

    public function testAGoalShortOfTargetIsAtRiskAndFarShortIsOffTrack(): void
    {
        $goal = self::goal('coverage', 60.0, '%');

        // 54 of 60 is 90% attained — short, but recoverable inside the period.
        self::assertSame(DepartmentGoal::AT_RISK, $goal->state(self::kpi('coverage', 54.0, '%')));
        // 40 of 60 is 67% — the exec needs to know, so it stops being "at risk".
        self::assertSame(DepartmentGoal::OFF_TRACK, $goal->state(self::kpi('coverage', 40.0, '%')));
    }

    public function testAGoalWhoseModuleReportsNothingIsAwaitingAndNotZeroPerCent(): void
    {
        $goal = self::goal('sightings', 400.0);

        // No KPI at all: the module is not attached, or not installed.
        self::assertNull($goal->attainment(null));
        self::assertSame(DepartmentGoal::AWAITING, $goal->state(null));
        self::assertSame('Awaiting module', $goal->stateLabel(null));

        // Attached, but the figure itself is unknown (PL·03 answers null for "no track").
        $unknown = new DepartmentKpi('sightings', 'Sightings', 'wildlife', 'Wildlife', null);
        self::assertNull($goal->attainment($unknown));
        self::assertSame(DepartmentGoal::AWAITING, $goal->state($unknown));
    }

    public function testTheTargetReadsAsTheCardPrintsIt(): void
    {
        self::assertSame("\u{2265} 2,000 km", self::goal('distance', 2000.0, 'km')->targetLabel());
        self::assertSame("\u{2265} 60%", self::goal('coverage', 60.0, '%')->targetLabel());
        self::assertSame("\u{2265} 20", self::goal('drone', 20.0)->targetLabel());
    }

    public function testAPeriodOutsideTheReportingVocabularyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::goal('coverage', 60.0, '%')->setPeriod('fortnight');
    }

    private static function goal(string $kpiRef, float $target, string $unit = ''): DepartmentGoal
    {
        return new DepartmentGoal()
            ->setStatement(\sprintf('%s at least %s', ucfirst($kpiRef), $target))
            ->setKpiRef($kpiRef)
            ->setTargetValue($target)
            ->setTargetUnit($unit);
    }

    private static function kpi(string $key, ?float $value, string $unit = ''): DepartmentKpi
    {
        return new DepartmentKpi($key, ucfirst($key), 'patrols', 'Patrols', $value, $unit);
    }
}
