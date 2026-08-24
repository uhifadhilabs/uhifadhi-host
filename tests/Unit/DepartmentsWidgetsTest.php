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
use Uhifadhi\Model\DepartmentsWidgets;
use Uhifadhi\Model\WidgetGroup;

/**
 * The departments surface's catalogue: the pinned contract every one of the six
 * agents building this screen writes against. A widget's id is what a stored
 * preference names and what the partial is filed under, so these are asserted
 * literally rather than derived.
 */
final class DepartmentsWidgetsTest extends TestCase
{
    public function testTheSurfaceIsOrgWideDepartments(): void
    {
        self::assertSame('departments', DepartmentsWidgets::SURFACE);
        self::assertSame('departments', DepartmentsWidgets::catalog()->surface);
    }

    public function testTheLibraryShipsTheFiveHeadedSections(): void
    {
        $ids = array_map(
            static fn (WidgetGroup $group): string => $group->id,
            DepartmentsWidgets::catalog()->groups(),
        );

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $ids);
    }

    public function testTheWidgetsShipInTheirDesignOrder(): void
    {
        self::assertSame(
            ['kpis', 'cards', 'registry', 'matrix', 'lanes', 'lens', 'shared'],
            DepartmentsWidgets::catalog()->ids(),
        );
    }

    /**
     * @return iterable<string, array{string, string, int, list<int>, bool}>
     */
    public static function widgets(): iterable
    {
        yield 'kpis' => ['kpis', 'a', 12, [12, 6], true];
        yield 'cards' => ['cards', 'a', 12, [12], true];
        yield 'registry' => ['registry', 'b', 12, [12, 6], true];
        yield 'matrix' => ['matrix', 'c', 12, [12], false];
        yield 'lanes' => ['lanes', 'd', 12, [12], false];
        yield 'lens' => ['lens', 'e', 12, [12], false];
        yield 'shared' => ['shared', 'a', 12, [12, 6], false];
    }

    /**
     * @param list<int> $spans
     */
    #[DataProvider('widgets')]
    public function testEachWidgetDeclaresItsGroupSpansAndDefault(
        string $id,
        string $group,
        int $cols,
        array $spans,
        bool $on,
    ): void {
        $widget = DepartmentsWidgets::catalog()->get($id);

        self::assertSame($group, $widget->group);
        self::assertSame($cols, $widget->cols);
        self::assertSame($spans, $widget->spans);
        self::assertSame($on, $widget->on);
    }

    public function testTheSurfaceShipsOnePresetPerDesignDirection(): void
    {
        // The five directions were drawn as whole screens, so the library must be
        // able to adopt one whole — not only to assemble it widget by widget.
        $presets = DepartmentsWidgets::catalog()->presets();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_column($presets, 'id'));
        self::assertSame(
            ['Department cards', 'Team view', 'Configuration matrix', 'Org chart', 'Lens preview'],
            array_column($presets, 'label'),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function presets(): iterable
    {
        yield 'a' => ['a', ['kpis', 'cards', 'shared']];
        yield 'b' => ['b', ['registry', 'kpis']];
        yield 'c' => ['c', ['matrix', 'kpis']];
        yield 'd' => ['d', ['lanes', 'kpis']];
        yield 'e' => ['e', ['lens', 'cards']];
    }

    /**
     * @param list<string> $layout
     */
    #[DataProvider('presets')]
    public function testEachPresetIsItsDesignsWholeLayout(string $id, array $layout): void
    {
        $preset = DepartmentsWidgets::catalog()->preset($id);

        self::assertNotNull($preset);
        self::assertSame($layout, $preset->ids(), 'listed is on, in this order; absent is off');
    }

    public function testAPresetSaysWhatItsDirectionCostsInTheDesignsOwnWords(): void
    {
        // The compare index's trade-off line is what the section already says, so
        // the preset says the same thing — one sentence, one source.
        $catalog = DepartmentsWidgets::catalog();
        $groups = array_column($catalog->groups(), null, 'id');

        foreach ($catalog->presets() as $preset) {
            self::assertSame($groups[$preset->id]->description, $preset->description);
        }
    }

    public function testEveryWidgetHasAPartial(): void
    {
        foreach (DepartmentsWidgets::catalog()->ids() as $id) {
            self::assertFileExists(
                \dirname(__DIR__, 2).'/templates/departments/_w_'.$id.'.html.twig',
                \sprintf('the "%s" widget ships a partial', $id),
            );
        }
    }
}
