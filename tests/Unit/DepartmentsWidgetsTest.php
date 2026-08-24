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
