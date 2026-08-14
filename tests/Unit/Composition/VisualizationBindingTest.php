<?php

declare(strict_types=1);

namespace App\Tests\Unit\Composition;

use App\Composition\Entity\Visualization;
use PHPUnit\Framework\TestCase;

/**
 * A visualization binds to a dataset by key and plots two of its columns (xAxis/yAxis are column
 * names, not display labels). Without a dataset key it is unbound — there is nothing for the renderer
 * to plot.
 */
final class VisualizationBindingTest extends TestCase
{
    public function testBindsToADatasetKeyAndColumns(): void
    {
        $viz = (new Visualization())
            ->setDatasetKey('landcover_area')
            ->setXAxis('class')
            ->setYAxis('area_km2')
            ->setColourBy('class');

        self::assertTrue($viz->isBound());
        self::assertSame('landcover_area', $viz->getDatasetKey());
        self::assertSame('class', $viz->getXAxis(), 'xAxis is now a dataset column name');
        self::assertSame('area_km2', $viz->getYAxis(), 'yAxis is now a dataset column name');
        self::assertSame('class', $viz->getColourBy());
    }

    public function testIsUnboundWithoutADatasetKey(): void
    {
        self::assertFalse((new Visualization())->isBound(), 'a fresh viz has no dataset');
        self::assertFalse((new Visualization())->setDatasetKey('')->isBound(), 'an empty key is not a binding');
        self::assertFalse((new Visualization())->setDatasetKey(null)->isBound());
    }
}
