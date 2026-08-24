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
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetGroup;

/**
 * The catalogue is the contract a dashboard surface registers: its widgets, the
 * groups the library files them under, and the spans each widget may take. It is
 * CODE, not input — so a malformed catalogue is a programming error and throws
 * at construction, where the container boots, rather than degrading silently at
 * render time the way a stored preference does.
 */
final class WidgetCatalogTest extends TestCase
{
    private static function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            'departments',
            [
                new WidgetGroup('shape', 'The shape of the org', 'Who reports to whom, and where the gaps are.'),
                new WidgetGroup('people', 'People', 'Headcount and its movement.'),
            ],
            [
                new Widget('tree', 'Department tree', 'shape'),
                new Widget('vacancies', 'Vacant positions', 'shape', cols: 6, spans: [9, 6, 3]),
                new Widget('headcount', 'Headcount', 'people', cols: 6, spans: [9, 6, 3], on: false),
            ],
        );
    }

    public function testACatalogueKnowsItsSurfaceWidgetsAndOrder(): void
    {
        $catalog = self::catalog();

        self::assertSame('departments', $catalog->surface);
        // Declaration order IS the default order — a surface states its layout by
        // listing widgets in the order the design draws them.
        self::assertSame(['tree', 'vacancies', 'headcount'], $catalog->ids());
        self::assertTrue($catalog->has('tree'));
        self::assertFalse($catalog->has('nope'));
        self::assertSame('Department tree', $catalog->get('tree')->label);
    }

    public function testAWidgetDefaultsToFullWidthAndOn(): void
    {
        $tree = self::catalog()->get('tree');

        self::assertSame(12, $tree->cols);
        self::assertSame([12, 9, 6, 3], $tree->spans);
        self::assertTrue($tree->on);
    }

    public function testAWidgetOffersOnlyTheSpansItDeclares(): void
    {
        $catalog = self::catalog();

        self::assertSame([12, 9, 6, 3], $catalog->spans('tree'));
        // A half-width plate is never offered the full row.
        self::assertSame([9, 6, 3], $catalog->spans('vacancies'));
    }

    public function testASpanIsClampedToTheNearestAllowedOneTiesGoingWider(): void
    {
        $catalog = self::catalog();

        self::assertSame(9, $catalog->clamp('vacancies', 12));
        self::assertSame(9, $catalog->clamp('vacancies', 400));
        self::assertSame(6, $catalog->clamp('tree', 7));
        // 7 is one from 6 and two from 9; 10 is one from 9 and two from 12.
        self::assertSame(9, $catalog->clamp('tree', 10));
    }

    public function testTheLibraryReadsTheCatalogueAsHeadedGroups(): void
    {
        $groups = self::catalog()->groups();

        self::assertSame(['shape', 'people'], array_column($groups, 'id'));
        self::assertSame('The shape of the org', $groups[0]->label);
        self::assertSame('Who reports to whom, and where the gaps are.', $groups[0]->description);
    }

    public function testAWidgetInAGroupTheCatalogueDoesNotDeclareIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ghosts"');

        new WidgetCatalog('departments', [new WidgetGroup('shape', 'Shape', 'x')], [
            new Widget('tree', 'Department tree', 'ghosts'),
        ]);
    }

    public function testARepeatedWidgetIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WidgetCatalog('departments', [new WidgetGroup('shape', 'Shape', 'x')], [
            new Widget('tree', 'Department tree', 'shape'),
            new Widget('tree', 'Another tree', 'shape'),
        ]);
    }

    public function testARepeatedGroupIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WidgetCatalog('departments', [
            new WidgetGroup('shape', 'Shape', 'x'),
            new WidgetGroup('shape', 'Shape again', 'y'),
        ], [new Widget('tree', 'Department tree', 'shape')]);
    }

    public function testASurfaceMustNameItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WidgetCatalog('', [new WidgetGroup('shape', 'Shape', 'x')], [
            new Widget('tree', 'Department tree', 'shape'),
        ]);
    }

    public function testADefaultSpanOutsideTheWidgetsOwnSpansIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Widget('tree', 'Department tree', 'shape', cols: 12, spans: [9, 6, 3]);
    }

    public function testASpanOutsideTheTwelveColumnGridIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Widget('tree', 'Department tree', 'shape', cols: 5, spans: [5]);
    }

    public function testSpansMustBeDeclaredWidestFirst(): void
    {
        // The library draws the chips in declaration order; widest-first is the
        // design's reading, and a catalogue that declares them backwards would
        // silently render backwards on one surface only.
        $this->expectException(\InvalidArgumentException::class);

        new Widget('tree', 'Department tree', 'shape', cols: 6, spans: [3, 6, 9]);
    }
}
