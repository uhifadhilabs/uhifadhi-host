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
use Uhifadhi\Model\DepartmentDetailWidgets;
use Uhifadhi\Model\DepartmentPerformanceWidgets;
use Uhifadhi\Model\PerformanceBoardWidgets;
use Uhifadhi\Model\WidgetCatalog;

/**
 * The three department surfaces' catalogues. {@see WidgetCatalog} refuses a preset naming a
 * widget the surface does not ship, or a width it does not offer — so simply CONSTRUCTING each
 * catalogue proves every design in it is renderable. These tests make that failure land here,
 * on the catalogue, rather than on whichever page opens first.
 *
 * They also pin the two invariants the surfaces would otherwise drift on: every widget has a
 * partial to render with, and the two per-department surfaces store ONE layout per person rather
 * than one per department.
 */
final class DepartmentSurfaceCatalogsTest extends TestCase
{
    /** @return iterable<string, array{WidgetCatalog, string}> */
    public static function surfaces(): iterable
    {
        yield 'detail' => [DepartmentDetailWidgets::catalog(), 'departments/detail/_w_%s.html.twig'];
        yield 'performance' => [DepartmentPerformanceWidgets::catalog(), 'departments/detail/_p_%s.html.twig'];
        yield 'board' => [PerformanceBoardWidgets::catalog(), 'departments/performance/_b_%s.html.twig'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function testEveryWidgetHasAPartialToRenderWith(WidgetCatalog $catalog, string $pattern): void
    {
        $templates = \dirname(__DIR__, 2).'/templates/';
        foreach ($catalog->ids() as $id) {
            self::assertFileExists($templates.\sprintf($pattern, $id), \sprintf('The "%s" surface ships widget "%s" with no partial.', $catalog->surface, $id));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function testEveryPresetIsARenderableLayout(WidgetCatalog $catalog, string $pattern): void
    {
        self::assertNotSame([], $catalog->presets(), \sprintf('The "%s" surface offers no design to start from.', $catalog->surface));

        foreach ($catalog->presets() as $preset) {
            foreach ($preset->layout as $id => $cols) {
                self::assertTrue($catalog->has($id));
                self::assertContains($cols, $catalog->spans($id));
            }
        }
    }

    public function testTheTwoPerDepartmentSurfacesAreNamedDistinctlyFromTheOrgWideOnes(): void
    {
        // A stored preference row is keyed by the surface string, so two surfaces sharing one
        // would silently share a layout. These four must stay four.
        $surfaces = [
            DepartmentDetailWidgets::SURFACE,
            DepartmentPerformanceWidgets::SURFACE,
            PerformanceBoardWidgets::SURFACE,
            \Uhifadhi\Model\DepartmentsWidgets::SURFACE,
        ];

        self::assertSame($surfaces, array_values(array_unique($surfaces)));
    }
}
