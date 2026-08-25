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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Model\TeamWidgets;
use Uhifadhi\Model\WidgetGroup;

/**
 * The team surface's catalogue: the pinned contract. A widget's id is what a stored preference
 * names and what its partial is filed under, so these are asserted literally rather than derived.
 */
final class TeamWidgetsTest extends TestCase
{
    public function testTheSurfaceIsOrgWideTeam(): void
    {
        self::assertSame('team', TeamWidgets::SURFACE);
        self::assertSame('team', TeamWidgets::catalog()->surface);
    }

    public function testTheLibraryShipsTwoHeadedSections(): void
    {
        $ids = array_map(
            static fn (WidgetGroup $group): string => $group->id,
            TeamWidgets::catalog()->groups(),
        );

        self::assertSame(['people', 'positions'], $ids);
    }

    public function testTheWidgetsShipInTheirDesignOrder(): void
    {
        self::assertSame(
            ['people', 'positions_a', 'positions_b', 'positions_c', 'positions_d', 'positions_e'],
            TeamWidgets::catalog()->ids(),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function widgets(): iterable
    {
        yield 'people' => ['people', 'people', true];
        yield 'positions_a' => ['positions_a', 'positions', true];
        yield 'positions_b' => ['positions_b', 'positions', false];
        yield 'positions_c' => ['positions_c', 'positions', false];
        yield 'positions_d' => ['positions_d', 'positions', false];
        yield 'positions_e' => ['positions_e', 'positions', false];
    }

    #[DataProvider('widgets')]
    public function testEachWidgetDeclaresItsGroupItsFullRowAndItsDefault(string $id, string $group, bool $on): void
    {
        $widget = TeamWidgets::catalog()->get($id);

        self::assertSame($group, $widget->group);
        self::assertSame($on, $widget->on);
        // Each positions direction is a whole screen's worth of table; half of one reads as
        // neither, so 12 is the only span any team widget offers.
        self::assertSame(12, $widget->cols);
        self::assertSame([12], $widget->spans);
    }

    public function testExactlyOnePositionsDirectionIsOnByDefault(): void
    {
        // The five are ALTERNATIVES, not additions: a dashboard showing two of them shows the
        // same list twice.
        $on = array_filter(
            TeamWidgets::catalog()->ids(),
            static fn (string $id): bool => str_starts_with($id, 'positions_')
                && TeamWidgets::catalog()->get($id)->on,
        );

        self::assertSame(['positions_a'], array_values($on));
    }

    public function testTheSurfaceShipsOnePresetPerDesignDirection(): void
    {
        $presets = TeamWidgets::catalog()->presets();

        self::assertSame(
            ['positions_a', 'positions_b', 'positions_c', 'positions_d', 'positions_e'],
            array_column($presets, 'id'),
        );
        self::assertSame(
            ['Grouped table', 'Department filter chips', 'Department cards', 'Qualified names', 'Split manager'],
            array_column($presets, 'label'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function presets(): iterable
    {
        foreach (['positions_a', 'positions_b', 'positions_c', 'positions_d', 'positions_e'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('presets')]
    public function testEachPresetIsPeoplePlusExactlyOneDirection(string $id): void
    {
        $preset = TeamWidgets::catalog()->preset($id);

        self::assertNotNull($preset);
        // Listed is on, in this order; absent is off — so adopting a direction can never leave
        // the previous direction's table behind beside the new one.
        self::assertSame(['people', $id], $preset->ids());
        self::assertSame(12, $preset->cols('people'));
        self::assertSame(12, $preset->cols($id));
    }

    public function testAFreshPersonOpensOnTheGroupedTable(): void
    {
        // The direction whose create row picks the department by WHERE you type — the only one
        // in which a first-time visitor cannot forget the department.
        self::assertSame('positions_a', TeamWidgets::DEFAULT_PRESET);
        self::assertSame('positions_a', TeamWidgets::catalog()->defaultPresetId());
    }

    public function testEveryWidgetHasAPartial(): void
    {
        foreach (TeamWidgets::catalog()->ids() as $id) {
            self::assertFileExists(
                \dirname(__DIR__, 2).'/templates/team/_w_'.$id.'.html.twig',
                \sprintf('the "%s" widget ships a partial', $id),
            );
        }
    }
}
