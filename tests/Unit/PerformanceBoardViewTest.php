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
use Uhifadhi\Model\PerformanceBoardView;
use Uhifadhi\Module\DepartmentKpi;
use UhifadhiLabs\Trunk\Entity\Module;

/**
 * The board's arithmetic, away from HTTP.
 *
 * Everything the design asks the board to say — which of three emptinesses a row is in, where a
 * figure stands in its column, what place a department holds and whether it moved — is decided
 * here, so a template cannot decide it differently and a functional test does not have to boot a
 * module to prove it.
 */
final class PerformanceBoardViewTest extends TestCase
{
    public function testAPeriodIsOneOfFourAndAnythingElseIsTheMonth(): void
    {
        self::assertSame('week', PerformanceBoardView::period('week'));
        self::assertSame('quarter', PerformanceBoardView::period('quarter'));
        self::assertSame('year', PerformanceBoardView::period('year'));
        self::assertSame('month', PerformanceBoardView::period(null));
        self::assertSame('month', PerformanceBoardView::period('decade'));
        self::assertSame('month', PerformanceBoardView::period('../etc/passwd'));
    }

    public function testEachPeriodStatesItsOwnWindowAndLabel(): void
    {
        $now = new \DateTimeImmutable('2026-08-19 14:00:00');

        $month = PerformanceBoardView::window('month', $now);
        self::assertSame('2026-08-01', $month['start']->format('Y-m-d'));
        self::assertSame('2026-09-01', $month['end']->format('Y-m-d'));
        self::assertSame('August 2026', $month['label']);

        $week = PerformanceBoardView::window('week', $now);
        self::assertSame('2026-08-17', $week['start']->format('Y-m-d'), 'a week starts on its Monday');
        self::assertSame('Wk 34 2026', $week['label']);

        $quarter = PerformanceBoardView::window('quarter', $now);
        self::assertSame('2026-07-01', $quarter['start']->format('Y-m-d'));
        self::assertSame('Q3 2026', $quarter['label']);

        $year = PerformanceBoardView::window('year', $now);
        self::assertSame('2026-01-01', $year['start']->format('Y-m-d'));
        self::assertSame('2026', $year['label']);
    }

    public function testTheCodeMarkIsInitialsForManyWordsAndTwoLettersForOne(): void
    {
        self::assertSame('PS', PerformanceBoardView::code('Protection Service'));
        self::assertSame('CD', PerformanceBoardView::code('Community Development'));
        self::assertSame('EC', PerformanceBoardView::code('Ecology'));
        self::assertSame('HR', PerformanceBoardView::code('Human Resource'));
        self::assertSame('?', PerformanceBoardView::code(''));
    }

    /**
     * The design's three states, and the sentence that makes them matter: "attached but computing
     * nothing" and "nothing attached" are DIFFERENT emptinesses, and the board says which.
     */
    public function testADepartmentIsInOneOfThreeStatesAndNeverJustNotReporting(): void
    {
        $rows = self::board();

        self::assertSame(['reporting', 'reporting', 'awaiting', 'unattached'], array_column($rows, 'state'));
        self::assertSame([true, true, false, false], array_column($rows, 'reporting'));
        self::assertSame(['all' => 4, 'reporting' => 2, 'awaiting' => 1, 'unattached' => 1], PerformanceBoardView::counts($rows));
    }

    public function testTheTintIsASixStepStandingWithinTheColumnAndNeverAVerdict(): void
    {
        $rows = self::board();

        // Two peers: the larger leads the column, the smaller trails it, and an unmeasured cell
        // is h0 — the dashed slot, never a zero.
        self::assertSame('h5', $rows[0]['cells'][0]['heat']);
        self::assertSame('h1', $rows[1]['cells'][0]['heat']);
        self::assertSame('h0', $rows[2]['cells'][0]['heat']);

        // A column nobody else is in invents no comparison at all.
        $lone = PerformanceBoardView::rows(
            [self::department('Ecology', ['patrols'])],
            [1 => [self::kpi('patrols', 88.0)]],
            [['key' => 'patrols', 'label' => 'Patrols', 'unit' => '', 'moduleName' => 'Patrols']],
            [],
            [],
            [],
        );
        self::assertSame('', $lone[0]['cells'][0]['heat']);
    }

    public function testRankIsAPlacingOnOneColumnAndItsShiftIsThatPlacingAPeriodAgo(): void
    {
        $rows = self::board();

        self::assertSame(1, $rows[0]['rank']);
        self::assertSame(0, $rows[0]['shift'], 'Protection Service held first');
        self::assertSame(2, $rows[1]['rank']);
        // A department with no figure is UNRANKED, not last.
        self::assertNull($rows[2]['rank']);
        self::assertNull($rows[2]['shift']);
    }

    public function testADepartmentThatOvertookAnotherShowsAnUpwardShift(): void
    {
        $columns = [['key' => 'distance', 'label' => 'Distance', 'unit' => 'km', 'moduleName' => 'Patrols']];
        $rows = PerformanceBoardView::rows(
            [self::department('Ecology', ['patrols'], 1), self::department('Protection Service', ['patrols'], 2)],
            [
                1 => [self::kpi('distance', 2480.0, 'km', 1200.0)],
                2 => [self::kpi('distance', 1340.0, 'km', 2000.0)],
            ],
            $columns,
            [],
            [],
            [],
        );

        self::assertSame([1, 2], array_column($rows, 'rank'));
        self::assertSame(1, $rows[0]['shift'], 'Ecology moved up one place');
        self::assertSame(-1, $rows[1]['shift']);

        // And that move is the first thing the rank-shift panel says.
        $mover = PerformanceBoardView::shifts($rows, $columns)[0];
        self::assertSame('mover', $mover['kind']);
        self::assertSame('Ecology', $mover['name']);
        self::assertSame(1, $mover['places']);
    }

    public function testSearchMatchesADepartmentByItsNameOrByAModuleItAttaches(): void
    {
        $rows = self::board();

        self::assertSame(['Protection Service'], self::names(PerformanceBoardView::filter($rows, 'protec', 'all')));
        self::assertCount(3, PerformanceBoardView::filter($rows, 'patrols', 'all'), 'three departments attach the Patrols module');
        self::assertSame([], PerformanceBoardView::filter($rows, 'nothing like this', 'all'));

        // The counts follow the search, so a filter never promises rows the search removed.
        self::assertSame(
            ['all' => 3, 'reporting' => 2, 'awaiting' => 1, 'unattached' => 0],
            PerformanceBoardView::counts(PerformanceBoardView::filter($rows, 'patrols', 'all')),
        );
    }

    public function testEachBucketShowsOnlyItsOwnEmptiness(): void
    {
        $rows = self::board();

        self::assertSame(['Protection Service', 'Ecology'], self::names(PerformanceBoardView::filter($rows, '', 'reporting')));
        self::assertSame(['Community Development'], self::names(PerformanceBoardView::filter($rows, '', 'awaiting')));
        self::assertSame(['Human Resource'], self::names(PerformanceBoardView::filter($rows, '', 'unattached')));
        self::assertCount(4, PerformanceBoardView::filter($rows, '', 'anything-unknown'));
    }

    /** An absent KPI is not a small one: it sorts LAST in both directions. */
    public function testSortingPutsUnmeasuredRowsLastInBothDirections(): void
    {
        $rows = self::board();

        self::assertSame(
            ['Protection Service', 'Ecology', 'Community Development', 'Human Resource'],
            self::names(PerformanceBoardView::sort($rows, 'patrols', 'asc', self::columns())),
        );
        self::assertSame(
            ['Ecology', 'Protection Service', 'Community Development', 'Human Resource'],
            self::names(PerformanceBoardView::sort($rows, 'patrols', 'desc', self::columns())),
            'the unmeasured rows stay last even reversed',
        );
        self::assertSame(
            ['Community Development', 'Ecology', 'Human Resource', 'Protection Service'],
            self::names(PerformanceBoardView::sort($rows, 'name', 'asc', self::columns())),
        );
        self::assertSame(
            ['Protection Service', 'Human Resource', 'Ecology', 'Community Development'],
            self::names(PerformanceBoardView::sort($rows, 'name', 'desc', self::columns())),
        );
        self::assertSame(
            ['Protection Service', 'Ecology', 'Community Development', 'Human Resource'],
            self::names(PerformanceBoardView::sort($rows, 'rank', 'asc', self::columns())),
        );
    }

    public function testTheShiftsPanelIsAboutMovementAndCountsTheEmptyRowsOnce(): void
    {
        $rows = self::board();
        $kinds = array_map(
            static fn (array $fact): string => \is_string($fact['kind']) ? $fact['kind'] : '',
            PerformanceBoardView::shifts($rows, self::columns()),
        );

        self::assertContains('leader', $kinds);
        self::assertContains('divergence', $kinds);
        self::assertContains('emptiness', $kinds);
        self::assertLessThanOrEqual(4, \count($kinds), 'the design is a fixed four-item list, not one line per bad cell');
        self::assertSame($kinds, array_unique($kinds), 'each kind is said once');
    }

    public function testWithNoDepartmentAtAllThereIsNothingToSayAndTheListSaysNothing(): void
    {
        self::assertSame([], PerformanceBoardView::shifts([], self::columns()));
        self::assertSame(['all' => 0, 'reporting' => 0, 'awaiting' => 0, 'unattached' => 0], PerformanceBoardView::counts([]));
    }

    /**
     * Three departments in the design's three states: one reporting, one attached-but-silent, one
     * with no module at all.
     *
     * @return list<array{department: Department, code: string, state: string, reporting: bool,
     *     moduleCount: int, areas: list<string>, positions: int, members: int, rank: int|null,
     *     shift: int|null, search: string,
     *     cells: list<array{kpi: DepartmentKpi|null, heat: string}>}>
     */
    private static function board(): array
    {
        return PerformanceBoardView::rows(
            [
                self::department('Protection Service', ['patrols'], 1),
                self::department('Ecology', ['patrols'], 2),
                self::department('Community Development', ['patrols'], 3),
                self::department('Human Resource', [], 4),
            ],
            [
                1 => [self::kpi('patrols', 154.0, '', 141.0), self::kpi('distance', 2480.0, 'km', 2300.0)],
                2 => [self::kpi('patrols', 88.0, '', 79.0), self::kpi('distance', 1340.0, 'km', 1500.0)],
                3 => [],
                4 => [],
            ],
            self::columns(),
            [1 => ['Ngorongoro Conservation Area'], 2 => ['Ngorongoro Conservation Area'], 3 => [], 4 => []],
            [1 => 3, 2 => 2, 3 => 2, 4 => 2],
            [1 => 42, 2 => 3, 3 => 9, 4 => 3],
        );
    }

    /** @return list<array{key: string, label: string, unit: string, moduleName: string}> */
    private static function columns(): array
    {
        return [
            ['key' => 'patrols', 'label' => 'Patrols', 'unit' => '', 'moduleName' => 'Patrols'],
            ['key' => 'distance', 'label' => 'Distance', 'unit' => 'km', 'moduleName' => 'Patrols'],
        ];
    }

    /** @param list<string> $moduleSlugs */
    private static function department(string $name, array $moduleSlugs, int $id = 1): Department
    {
        $department = new Department()->setName($name);
        foreach ($moduleSlugs as $slug) {
            $department->addModule(new Module()->setSlug($slug)->setName(ucfirst($slug)));
        }

        // The id Doctrine would have assigned — the KPI maps are keyed by it.
        $property = new \ReflectionProperty(Department::class, 'id');
        $property->setValue($department, $id);

        return $department;
    }

    private static function kpi(string $key, ?float $value, string $unit = '', ?float $previous = null): DepartmentKpi
    {
        return new DepartmentKpi($key, ucfirst($key), 'patrols', 'Patrols', $value, $unit, $previous);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static function (array $row): string {
            $department = $row['department'];
            \assert($department instanceof Department);

            return (string) $department->getName();
        }, $rows);
    }
}
