<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\Chart\ChartSvgService;
use PHPUnit\Framework\TestCase;

/**
 * The generic plot engine turns (label, value) points into SVG in the app's chart dialect. Bar heights
 * scale with value, line/area draw paths, empty input draws nothing, and — because labels are dataset
 * values — they are escaped before they reach the markup.
 */
final class ChartSvgServiceTest extends TestCase
{
    public function testBarDrawsARectPerPointWithHeightProportionalToValue(): void
    {
        $svg = (new ChartSvgService())->bar([
            ['label' => 'Grassland', 'value' => 100.0],
            ['label' => 'Cropland', 'value' => 25.0],
        ], 'km2');

        self::assertStringStartsWith('<svg class="ch"', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertSame(2, substr_count($svg, '<rect'), 'one bar per point');
        self::assertStringContainsString('Grassland', $svg);
        self::assertStringContainsString('Cropland', $svg);

        preg_match_all('/<rect [^>]*height="([\d.]+)"/', $svg, $matches);
        self::assertGreaterThan((float) $matches[1][1], (float) $matches[1][0], 'the larger value draws the taller bar');
    }

    public function testLineAndAreaDrawPaths(): void
    {
        $service = new ChartSvgService();
        $points = [['label' => '2001', 'value' => 3.0], ['label' => '2002', 'value' => 8.0]];

        self::assertStringContainsString('<path', $service->line($points));
        self::assertStringContainsString('fill-opacity', $service->area($points), 'an area chart has a filled region');
    }

    public function testEmptyPointsRenderNothing(): void
    {
        $service = new ChartSvgService();

        self::assertSame('', $service->bar([]));
        self::assertSame('', $service->line([]));
        self::assertSame('', $service->area([]));
    }

    public function testDatasetLabelsAreEscaped(): void
    {
        $svg = (new ChartSvgService())->bar([['label' => '<script>', 'value' => 1.0]]);

        self::assertStringNotContainsString('<script>', $svg, 'a raw label must not reach the markup');
        self::assertStringContainsString('&lt;script&gt;', $svg);
    }
}
