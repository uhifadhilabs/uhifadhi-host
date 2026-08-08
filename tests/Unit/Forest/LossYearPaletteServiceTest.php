<?php

declare(strict_types=1);

namespace App\Tests\Unit\Forest;

use App\Forest\Service\LossYearPaletteService;
use PHPUnit\Framework\TestCase;

/**
 * The palette must stay identical to the JS ramp in
 * assets/controllers/map_controller.js — the chart bars and the map polygons
 * share these exact colours.
 */
final class LossYearPaletteServiceTest extends TestCase
{
    private LossYearPaletteService $palette;

    protected function setUp(): void
    {
        $this->palette = new LossYearPaletteService();
    }

    public function testTheRampEndpointsMatchTheStops(): void
    {
        self::assertSame('rgb(255,255,178)', $this->palette->colorFor(2001));
        self::assertSame('rgb(189,0,38)', $this->palette->colorFor(2023));
    }

    public function testAStopYearReturnsItsExactColor(): void
    {
        self::assertSame('rgb(254,204,92)', $this->palette->colorFor(2008));
        self::assertSame('rgb(253,141,60)', $this->palette->colorFor(2014));
        self::assertSame('rgb(240,59,32)', $this->palette->colorFor(2019));
    }

    public function testYearsBetweenStopsInterpolateLinearly(): void
    {
        // 2011 is halfway between the 2008 and 2014 stops.
        self::assertSame('rgb(254,173,76)', $this->palette->colorFor(2011));
    }

    public function testYearsOutsideTheDomainClampToTheNearestStop(): void
    {
        self::assertSame('rgb(255,255,178)', $this->palette->colorFor(1999));
        self::assertSame('rgb(189,0,38)', $this->palette->colorFor(2030));
    }
}
