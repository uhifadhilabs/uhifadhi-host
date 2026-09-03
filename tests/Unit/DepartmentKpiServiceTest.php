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
use Uhifadhi\Entity\Department;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Module\DepartmentKpiProviderInterface;
use Uhifadhi\Seam\Entity\Module;
use Uhifadhi\Service\DepartmentKpiService;

/**
 * The KPI seam's rules, stated as tests.
 *
 * A department computes NOTHING of its own: every figure is an attached module's KPI. So the
 * service's whole job is (a) ask only the providers whose module this department actually
 * attaches, (b) ask them once per department so the position slice is theirs alone, and (c)
 * report a module that is not attached — or attached but silent — as ABSENT rather than zero.
 */
final class DepartmentKpiServiceTest extends TestCase
{
    public function testOnlyProvidersWhoseModuleIsAttachedAreAsked(): void
    {
        $ecology = self::department('Ecology', ['patrols']);

        $patrols = new FakeDepartmentKpiProvider('patrols', [self::kpi('patrols', 88.0)]);
        $wildlife = new FakeDepartmentKpiProvider('wildlife', [self::kpi('sightings', 12.0)]);

        $kpis = new DepartmentKpiService([$patrols, $wildlife])->forDepartment($ecology, self::now());

        self::assertSame(['patrols'], array_map(static fn (DepartmentKpi $k): string => $k->key, $kpis));
        self::assertSame([$ecology], $patrols->asked);
        // Wildlife is not attached, so it is never even consulted — and contributes no zero.
        self::assertSame([], $wildlife->asked);
    }

    public function testEachDepartmentIsAskedSeparatelySoTheSliceIsItsOwn(): void
    {
        $protection = self::department('Protection Service', ['patrols']);
        $ecology = self::department('Ecology', ['patrols']);

        // One shared module, two departments: the provider must be asked twice, once per
        // department, because the split is by the RECORDING PERSON'S position — not by module.
        $patrols = new FakeDepartmentKpiProvider('patrols', [self::kpi('patrols', 154.0)]);

        $byDepartment = new DepartmentKpiService([$patrols])
            ->forDepartments([$protection, $ecology], self::now());

        self::assertSame([$protection, $ecology], $patrols->asked);
        self::assertSame([$protection->getId(), $ecology->getId()], array_keys($byDepartment));
        self::assertCount(1, $byDepartment[(int) $protection->getId()]);
        self::assertCount(1, $byDepartment[(int) $ecology->getId()]);
    }

    public function testADepartmentWithNoAttachedModuleGetsAnEmptyListNeverZeros(): void
    {
        $tourism = self::department('Tourism', []);

        $byDepartment = new DepartmentKpiService([new FakeDepartmentKpiProvider('patrols', [self::kpi('patrols', 88.0)])])
            ->forDepartments([$tourism], self::now());

        // A key for every department asked about — an empty department is a real answer — but the
        // list is empty, so the board draws a dashed labelled slot and never a 0.
        self::assertSame([$tourism->getId()], array_keys($byDepartment));
        self::assertSame([], $byDepartment[(int) $tourism->getId()]);
    }

    public function testColumnsAreTheUnionOfEveryDepartmentsKpisInFirstSeenOrder(): void
    {
        $byDepartment = [
            1 => [self::kpi('patrols', 154.0), self::kpi('coverage', 71.0, '%')],
            2 => [self::kpi('coverage', 54.0, '%'), self::kpi('observations', 612.0)],
            3 => [],
        ];

        self::assertSame(
            ['patrols', 'coverage', 'observations'],
            array_map(static fn (array $c): string => $c['key'], DepartmentKpiService::columns($byDepartment)),
        );
    }

    public function testACellIsTheDepartmentsKpiForThatColumnOrNullWhichMeansNotAZero(): void
    {
        $kpis = [self::kpi('patrols', 88.0)];

        self::assertSame(88.0, DepartmentKpiService::cell($kpis, 'patrols')?->value);
        self::assertNull(DepartmentKpiService::cell($kpis, 'coverage'));
    }

    public function testPerAreaFiguresNeverLeakIntoTheHeadlineTotalsOrTheBoardsColumns(): void
    {
        $total = self::kpi('patrols', 88.0);
        $ngorongoro = new DepartmentKpi('patrols', 'Patrols', 'patrols', 'Patrols', 60.0, areaName: 'Ngorongoro');
        $pololeti = new DepartmentKpi('patrols', 'Patrols', 'patrols', 'Patrols', 28.0, areaName: 'Pololeti');

        $kpis = [$total, $ngorongoro, $pololeti];

        // The headline plate, the board cell and every goal read the TOTAL, once — summing the
        // list blind would have said 176 patrols.
        self::assertSame([$total], DepartmentKpiService::totals($kpis));
        self::assertSame(88.0, DepartmentKpiService::cell($kpis, 'patrols')?->value);
        self::assertCount(1, DepartmentKpiService::columns([1 => $kpis]));

        self::assertSame(['Ngorongoro', 'Pololeti'], array_keys(DepartmentKpiService::perArea($kpis)));
    }

    public function testAModuleThatCannotSplitByAreaSimplyReportsNoAreas(): void
    {
        // Nothing is invented: no area rows rather than a total divided by the area count.
        self::assertSame([], DepartmentKpiService::perArea([self::kpi('patrols', 88.0)]));
    }

    public function testACountKpiMovesInPercentAndAShareMovesInPoints(): void
    {
        $patrols = self::kpi('patrols', 88.0, '', 79.0);
        self::assertEqualsWithDelta(11.39, $patrols->delta() ?? 0.0, 0.01);
        self::assertSame('good', $patrols->direction());
        self::assertSame('+11.4%', $patrols->deltaLabel());

        $coverage = self::kpi('coverage', 54.0, '%', 61.0);
        self::assertEqualsWithDelta(-7.0, $coverage->delta() ?? 0.0, 0.01);
        self::assertSame('bad', $coverage->direction());
        self::assertSame('−7 pts', $coverage->deltaLabel());
    }

    public function testAKpiWithNoPreviousPeriodAndAnUnknownValueSayNothingRatherThanZero(): void
    {
        $first = self::kpi('patrols', 88.0);
        self::assertNull($first->delta());
        self::assertNull($first->deltaLabel());
        self::assertSame('', $first->direction());

        // PL·03 answers null for "no track recorded" — unknown coverage is not 0% coverage.
        $unknown = new DepartmentKpi('coverage', 'Coverage', 'patrols', 'Patrols', null, '%');
        self::assertFalse($unknown->isKnown());
        self::assertNull($unknown->delta());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-20 09:00:00');
    }

    private static function kpi(string $key, ?float $value, string $unit = '', ?float $previous = null): DepartmentKpi
    {
        return new DepartmentKpi($key, ucfirst($key), 'patrols', 'Patrols', $value, $unit, $previous);
    }

    /** @param list<string> $moduleSlugs */
    private static function department(string $name, array $moduleSlugs): Department
    {
        static $nextId = 1;
        \assert(\is_int($nextId));

        $department = new Department()->setName($name);
        self::assign($department, 'id', $nextId);
        ++$nextId;
        foreach ($moduleSlugs as $slug) {
            $department->addModule(new Module()->setSlug($slug)->setName(ucfirst($slug)));
        }

        return $department;
    }

    /** Doctrine assigns ids by reflection; a unit test may too, and the maps are keyed by id. */
    private static function assign(object $entity, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($entity::class, $property);
        $reflection->setValue($entity, $value);
    }
}

/**
 * A module's provider, without a module. Tag collection is a container concern (asserted in
 * {@see \Uhifadhi\Tests\Integration\DepartmentKpiTagTest}); WHAT the service does with the
 * providers it collected is this file's concern, so here they are handed in directly.
 */
final class FakeDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** @var list<Department> */
    public array $asked = [];

    /** @param list<DepartmentKpi> $kpis */
    public function __construct(
        private readonly string $slug,
        private readonly array $kpis,
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function kpisFor(Department $department, \DateTimeImmutable $now): array
    {
        $this->asked[] = $department;

        return $this->kpis;
    }
}
