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

namespace Uhifadhi\Model;

use Uhifadhi\Entity\Department;
use Uhifadhi\Module\DepartmentKpi;
use Uhifadhi\Service\DepartmentKpiService;

/**
 * THE ARITHMETIC OF `GET /departments/performance`, in one place with no HTTP and no HTML.
 *
 * The design (departments/performance.html) asks the board to say four things a template must not
 * be trusted to decide, because each is a comparison ACROSS rows:
 *
 *  1. **Which of three emptinesses a row is in.** `reporting` · `awaiting` (modules attached, none
 *     computing a KPI yet) · `unattached` (no module at all). Collapsing those into a boolean is
 *     the single easiest way to make this board lie: an empty row is not a zero, and a department
 *     with no module is not a department doing nothing.
 *  2. **Where a figure stands in its column** — the six-step `h0`–`h5` tint, which is a STANDING
 *     WITHIN A COLUMN and never a verdict. A column nobody else is in gets no tint at all: a lone
 *     department leads nothing.
 *  3. **What place a department holds, and whether it moved.** The rank is a placing on ONE column
 *     (the design's "Rank = distance patrolled"); the shift is the same placing computed from each
 *     KPI's own `previous` reading, so a period-over-period move needs no stored history.
 *  4. **What is worth saying about all of that** — {@see self::shifts()}, a fixed short list of
 *     FACTS. The words are the template's; the comparisons are here.
 *
 * Filtering, sorting and the bucket counts live here too, because on the board they are server
 * work: every control is a query parameter and the page works with no script at all.
 */
final class PerformanceBoardView
{
    /** The reporting periods the page can be read over — the design's period picker, exactly. */
    public const array PERIODS = ['week', 'month', 'quarter', 'year'];

    public const string DEFAULT_PERIOD = 'month';

    /** The buckets of {@see self::filter()}; anything else means "all". */
    public const array BUCKETS = ['all', 'reporting', 'awaiting', 'unattached'];

    /**
     * The column a rank is a placing ON. The design ranks by distance patrolled; a host whose
     * installed modules report no distance ranks on the board's first column instead, and the
     * legend names whichever it was rather than promising "distance".
     */
    private const string PREFERRED_RANK_KEY = 'distance';

    /** A requested period, or the month — never anything a caller sent. */
    public static function period(?string $requested): string
    {
        return \in_array($requested, self::PERIODS, true) ? $requested : self::DEFAULT_PERIOD;
    }

    /** A requested bucket, or all of them. */
    public static function bucket(?string $requested): string
    {
        return \in_array($requested, self::BUCKETS, true) ? $requested : 'all';
    }

    /**
     * The window the whole page is stated over: every figure, delta, rank and goal below belongs
     * to exactly this span, and the label is what the stance strip prints.
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, label: string}
     */
    public static function window(string $period, \DateTimeImmutable $now): array
    {
        $midnight = $now->setTime(0, 0);

        [$start, $end, $label] = match ($period) {
            'week' => [
                $start = $midnight->modify('monday this week'),
                $start->modify('+7 days'),
                \sprintf('Wk %s %s', $now->format('W'), $now->format('o')),
            ],
            'quarter' => [
                $start = $midnight->setDate((int) $now->format('Y'), (intdiv((int) $now->format('n') - 1, 3) * 3) + 1, 1),
                $start->modify('+3 months'),
                \sprintf('Q%d %s', intdiv((int) $now->format('n') - 1, 3) + 1, $now->format('Y')),
            ],
            'year' => [
                $start = $midnight->setDate((int) $now->format('Y'), 1, 1),
                $start->modify('+1 year'),
                $now->format('Y'),
            ],
            default => [
                $start = $midnight->setDate((int) $now->format('Y'), (int) $now->format('n'), 1),
                $start->modify('+1 month'),
                $now->format('F Y'),
            ],
        };

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    /**
     * The two-letter mark the design puts in front of a department: its initials when it has
     * several words, its first two letters when it has one (PS · CD · EC · HR).
     *
     * Derived rather than stored, because a code nobody types is not worth a column — and a
     * derived one cannot drift from the name beside it.
     */
    public static function code(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if ([] === $words) {
            return '?';
        }
        if (1 === \count($words)) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    }

    /**
     * One row per department, in board order — INCLUDING every department with nothing to show.
     *
     * @param list<Department>                                                          $departments
     * @param array<int, list<DepartmentKpi>>                                           $byDepartment
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     * @param array<int, list<string>>                                                  $areasByDepartment     where each department's modules run
     * @param array<int, int>                                                           $positionsByDepartment
     * @param array<int, int>                                                           $membersByDepartment
     *
     * @return list<array{department: Department, code: string, state: string, reporting: bool,
     *     moduleCount: int, areas: list<string>, positions: int, members: int, rank: int|null,
     *     shift: int|null, search: string,
     *     cells: list<array{kpi: DepartmentKpi|null, heat: string}>}>
     */
    public static function rows(
        array $departments,
        array $byDepartment,
        array $columns,
        array $areasByDepartment,
        array $positionsByDepartment,
        array $membersByDepartment,
    ): array {
        $peers = self::peers($byDepartment, $columns);
        $rankIndex = self::rankIndex($columns);
        $ranks = self::ranks($departments, $byDepartment, $columns, $rankIndex, previous: false);
        $before = self::ranks($departments, $byDepartment, $columns, $rankIndex, previous: true);

        $rows = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            $kpis = null !== $id ? ($byDepartment[$id] ?? []) : [];

            $cells = [];
            $reporting = false;
            foreach ($columns as $index => $column) {
                $kpi = DepartmentKpiService::cell($kpis, $column['key']);
                $reporting = $reporting || (null !== $kpi && $kpi->isKnown());
                $cells[] = ['kpi' => $kpi, 'heat' => self::heat($kpi, $peers[$index])];
            }

            $modules = array_values($department->getModules()->toArray());
            $rank = null !== $id ? ($ranks[$id] ?? null) : null;
            $was = null !== $id ? ($before[$id] ?? null) : null;
            $name = (string) $department->getName();

            $rows[] = [
                'department' => $department,
                'code' => self::code($name),
                'state' => $reporting ? 'reporting' : ([] === $modules ? 'unattached' : 'awaiting'),
                // The boolean the board carried before the three states existed, kept because
                // "did this row report anything" is still a fair question on its own.
                'reporting' => $reporting,
                'moduleCount' => \count($modules),
                'areas' => null !== $id ? ($areasByDepartment[$id] ?? []) : [],
                'positions' => null !== $id ? ($positionsByDepartment[$id] ?? 0) : 0,
                'members' => null !== $id ? ($membersByDepartment[$id] ?? 0) : 0,
                'rank' => $rank,
                // A shift needs BOTH placings: a department that did not exist in the column a
                // period ago did not "move up", it arrived.
                'shift' => null !== $rank && null !== $was ? $was - $rank : null,
                'search' => mb_strtolower($name.' '.implode(' ', array_map(
                    static fn (\UhifadhiLabs\Trunk\Entity\Module $module): string => (string) $module->getName(),
                    $modules,
                ))),
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * A cell's tint on the design's six-step scale: `h5` leads the column, `h1` trails it, `h0` is
     * the dashed slot where nothing was measured — and `''` (no tint) where there is nobody to be
     * compared with.
     *
     * @param list<float> $peers every known value in this column, ascending
     */
    public static function heat(?DepartmentKpi $kpi, array $peers): string
    {
        if (null === $kpi || !$kpi->isKnown()) {
            return 'h0';
        }
        if (\count($peers) < 2) {
            return '';
        }

        $value = (float) $kpi->value;
        $below = \count(array_filter($peers, static fn (float $peer): bool => $peer < $value));
        $standing = $below / (\count($peers) - 1);

        return match (true) {
            $standing >= 1.0 => 'h5',
            $standing >= .75 => 'h4',
            $standing >= .5 => 'h3',
            $standing > 0.0 => 'h2',
            default => 'h1',
        };
    }

    /**
     * The rows a search and a bucket leave: the search reads a department's name AND the modules
     * it attaches, because "which departments do patrols?" is the same question.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $query, string $bucket): array
    {
        $needle = mb_strtolower(trim($query));
        $bucket = self::bucket($bucket);

        return array_values(array_filter($rows, static function (array $row) use ($needle, $bucket): bool {
            $haystack = \is_string($row['search'] ?? null) ? $row['search'] : '';
            $matches = '' === $needle || str_contains($haystack, $needle);

            return $matches && ('all' === $bucket || $row['state'] === $bucket);
        }));
    }

    /**
     * How many rows are in each bucket. Counted from the rows HANDED IN, so the counts follow the
     * search and a filter never promises rows the search has already removed.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{all: int, reporting: int, awaiting: int, unattached: int}
     */
    public static function counts(array $rows): array
    {
        $tally = ['all' => \count($rows), 'reporting' => 0, 'awaiting' => 0, 'unattached' => 0];
        foreach ($rows as $row) {
            $state = \is_string($row['state'] ?? null) ? $row['state'] : '';
            if (isset($tally[$state])) {
                ++$tally[$state];
            }
        }

        return $tally;
    }

    /**
     * The board in the asked-for order.
     *
     * Two rules the design is explicit about:
     *  - a rank is a PLACING (1 is best) and every KPI is a QUANTITY (more is higher), so
     *    "ascending" means the opposite thing for each;
     *  - rows with no value for the sorted column sort LAST in BOTH directions — an absent KPI is
     *    not a small one.
     *
     * @param list<array<string, mixed>>                                                $rows
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     *
     * @return list<array<string, mixed>>
     */
    public static function sort(array $rows, string $sort, string $direction, array $columns = []): array
    {
        $descending = 'desc' === $direction;
        $index = null;
        foreach ($columns as $at => $column) {
            if ($column['key'] === $sort) {
                $index = $at;
            }
        }

        usort($rows, static function (array $a, array $b) use ($sort, $descending, $index): int {
            $byName = self::name($a) <=> self::name($b);
            if ('name' === $sort) {
                return $descending ? -$byName : $byName;
            }

            $left = self::sortable($a, $sort, $index);
            $right = self::sortable($b, $sort, $index);
            if (null === $left && null === $right) {
                return $byName;
            }
            if (null === $left) {
                return 1;
            }
            if (null === $right) {
                return -1;
            }

            $comparison = 'rank' === $sort ? $left <=> $right : $right <=> $left;

            return 0 === $comparison ? $byName : ($descending ? -$comparison : $comparison);
        });

        return $rows;
    }

    /**
     * What the rank-shift panel has to say: a short list of FACTS about movement, in the design's
     * order — who moved, who holds first, who went two ways at once, and how many rows are empty.
     *
     * Facts and not sentences: the words belong to the template, and a fact the board cannot
     * establish is simply absent rather than padded out.
     *
     * @param list<array<string, mixed>>                                                $rows
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     *
     * @return list<array<string, mixed>>
     */
    public static function shifts(array $rows, array $columns): array
    {
        if ([] === $rows) {
            return [];
        }

        $shifts = [];
        $rankColumn = $columns[self::rankIndex($columns)] ?? null;

        // Who moved, and by how much — the biggest upward move on the ranked column.
        $mover = null;
        foreach ($rows as $row) {
            $shift = \is_int($row['shift'] ?? null) ? $row['shift'] : 0;
            $best = \is_int($mover['shift'] ?? null) ? $mover['shift'] : 0;
            if ($shift > 0 && $shift > $best) {
                $mover = $row;
            }
        }
        if (null !== $mover && null !== $rankColumn) {
            $kpi = self::cellAt($mover, self::rankIndex($columns));
            $shifts[] = [
                'kind' => 'mover',
                'tone' => 'i',
                'icon' => 'lucide:trending-up',
                'name' => self::name($mover),
                'places' => $mover['shift'],
                'column' => $rankColumn['label'],
                'delta' => $kpi?->deltaLabel(),
            ];
        }

        // Who holds first, and on how much of the board.
        $leads = [];
        foreach ($rows as $row) {
            foreach (self::cells($row) as $cell) {
                if ('h5' === $cell['heat']) {
                    $leads[self::name($row)] = ($leads[self::name($row)] ?? 0) + 1;
                }
            }
        }
        arsort($leads);
        $leader = array_key_first($leads);
        if (null !== $leader && [] !== $columns) {
            $shifts[] = [
                'kind' => 'leader',
                'tone' => 'i',
                'icon' => 'lucide:target',
                'name' => $leader,
                'leads' => $leads[$leader],
                'columns' => \count($columns),
            ];
        }

        // Who went two ways at once — the reading a single figure hides.
        foreach ($rows as $row) {
            $up = null;
            $down = null;
            foreach (self::cells($row) as $cell) {
                $kpi = $cell['kpi'] ?? null;
                if (!$kpi instanceof DepartmentKpi) {
                    continue;
                }
                $up ??= 'good' === $kpi->direction() ? $kpi : null;
                $down ??= 'bad' === $kpi->direction() ? $kpi : null;
            }
            if (null !== $up && null !== $down) {
                $shifts[] = [
                    'kind' => 'divergence',
                    'tone' => 'w',
                    'icon' => 'lucide:trending-down',
                    'name' => self::name($row),
                    'fell' => $down->label,
                    'fellBy' => $down->deltaLabel(),
                    'rose' => $up->label,
                    'roseBy' => $up->deltaLabel(),
                ];

                break;
            }
        }

        // And the emptiness, named — which is the half of the board the design refuses to hide.
        $counts = self::counts($rows);
        if ($counts['awaiting'] + $counts['unattached'] > 0) {
            $shifts[] = [
                'kind' => 'emptiness',
                'tone' => 'i',
                'icon' => 'lucide:info',
                'empty' => $counts['awaiting'] + $counts['unattached'],
                'awaiting' => $counts['awaiting'],
                'unattached' => $counts['unattached'],
            ];
        }

        return $shifts;
    }

    /**
     * Which column a rank is a placing on: the design's distance if a module reports it, the
     * board's first column otherwise.
     *
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     */
    public static function rankIndex(array $columns): int
    {
        foreach ($columns as $index => $column) {
            if (self::PREFERRED_RANK_KEY === $column['key']) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Every known value per column, ascending — what a cell is told it stands among.
     *
     * @param array<int, list<DepartmentKpi>>                                           $byDepartment
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     *
     * @return array<int, list<float>>
     */
    private static function peers(array $byDepartment, array $columns): array
    {
        $peers = [];
        foreach ($columns as $index => $column) {
            $values = [];
            foreach ($byDepartment as $kpis) {
                $kpi = DepartmentKpiService::cell($kpis, $column['key']);
                if (null !== $kpi && $kpi->isKnown()) {
                    $values[] = (float) $kpi->value;
                }
            }
            sort($values);
            $peers[$index] = $values;
        }

        return $peers;
    }

    /**
     * Every department's placing on the ranked column, keyed by id — this period, or the one
     * before it when `$previous` is set, which is how a shift is known without stored history.
     *
     * Only MEASURED departments are placed: an unmeasured one is unranked, never last.
     *
     * @param list<Department>                                                          $departments
     * @param array<int, list<DepartmentKpi>>                                           $byDepartment
     * @param list<array{key: string, label: string, unit: string, moduleName: string}> $columns
     *
     * @return array<int, int>
     */
    private static function ranks(array $departments, array $byDepartment, array $columns, int $index, bool $previous): array
    {
        $column = $columns[$index] ?? null;
        if (null === $column) {
            return [];
        }

        $values = [];
        foreach ($departments as $department) {
            $id = $department->getId();
            if (null === $id) {
                continue;
            }
            $kpi = DepartmentKpiService::cell($byDepartment[$id] ?? [], $column['key']);
            $value = $previous ? $kpi?->previous : $kpi?->value;
            if (null !== $value) {
                $values[$id] = $value;
            }
        }

        arsort($values);

        $ranks = [];
        $place = 0;
        foreach (array_keys($values) as $id) {
            $ranks[$id] = ++$place;
        }

        return $ranks;
    }

    /** @param array<string, mixed> $row */
    private static function name(array $row): string
    {
        $department = $row['department'] ?? null;

        return $department instanceof Department ? (string) $department->getName() : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{kpi: DepartmentKpi|null, heat: string}>
     */
    private static function cells(array $row): array
    {
        /** @var list<array{kpi: DepartmentKpi|null, heat: string}> $cells */
        $cells = \is_array($row['cells'] ?? null) ? $row['cells'] : [];

        return $cells;
    }

    /** @param array<string, mixed> $row */
    private static function cellAt(array $row, int $index): ?DepartmentKpi
    {
        return self::cells($row)[$index]['kpi'] ?? null;
    }

    /**
     * The number a row is sorted by, or null when it has none for this column.
     *
     * @param array<string, mixed> $row
     */
    private static function sortable(array $row, string $sort, ?int $index): ?float
    {
        if ('rank' === $sort) {
            return \is_int($row['rank'] ?? null) ? (float) $row['rank'] : null;
        }
        if (null === $index) {
            return null;
        }

        $kpi = self::cellAt($row, $index);

        return null !== $kpi && $kpi->isKnown() ? (float) $kpi->value : null;
    }
}
