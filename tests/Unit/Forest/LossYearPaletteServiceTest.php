<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Unit\Forest;

use Uhifadhi\Forest\Service\LossYearPaletteService;
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
        self::assertSame('rgb(13,8,135)', $this->palette->colorFor(2001));
        self::assertSame('rgb(240,249,33)', $this->palette->colorFor(2023));
    }

    public function testAStopYearReturnsItsExactColor(): void
    {
        self::assertSame('rgb(126,3,168)', $this->palette->colorFor(2008));
        self::assertSame('rgb(204,68,120)', $this->palette->colorFor(2014));
        self::assertSame('rgb(248,149,64)', $this->palette->colorFor(2019));
    }

    public function testYearsBetweenStopsInterpolateLinearly(): void
    {
        // 2011 is halfway between the 2008 and 2014 stops.
        self::assertSame('rgb(165,36,144)', $this->palette->colorFor(2011));
    }

    public function testYearsOutsideTheDomainClampToTheNearestStop(): void
    {
        self::assertSame('rgb(13,8,135)', $this->palette->colorFor(1999));
        self::assertSame('rgb(240,249,33)', $this->palette->colorFor(2030));
    }
}
