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
use Uhifadhi\Service\AreaCardService;

final class AreaCardServiceTest extends TestCase
{
    private AreaCardService $service;

    protected function setUp(): void
    {
        $this->service = new AreaCardService();
    }

    public function testBoundsAndCentroidOfAMultiPolygon(): void
    {
        $geo = json_encode([
            'type' => 'MultiPolygon',
            'coordinates' => [[[[35.0, -3.5], [36.0, -3.5], [36.0, -2.5], [35.0, -2.5], [35.0, -3.5]]]],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([35.0, -3.5, 36.0, -2.5], $this->service->bounds($geo));
        self::assertSame([35.5, -3.0], $this->service->centroid([35.0, -3.5, 36.0, -2.5]));
    }

    public function testThumbnailIsAnEsriExportForTheBoundary(): void
    {
        $url = $this->service->thumbnailUrl([35.0, -3.5, 36.0, -2.5]);

        self::assertStringContainsString('World_Imagery/MapServer/export', $url);
        self::assertStringContainsString('bboxSR=4326', $url);
        self::assertStringContainsString('f=image', $url);
        self::assertStringContainsString('size=160,120', urldecode($url));
        // The padded bbox straddles the boundary centroid (35.5, -3.0).
        parse_str((string) parse_url(urldecode($url), \PHP_URL_QUERY), $q);
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', explode(',', (string) $q['bbox']));
        self::assertTrue($minLon < 35.5 && 35.5 < $maxLon, 'bbox straddles centroid longitude');
        self::assertTrue($minLat < -3.0 && -3.0 < $maxLat, 'bbox straddles centroid latitude');
    }

    public function testHaPerYear(): void
    {
        self::assertSame(140, $this->service->haPerYear(3214.0, 23));
        self::assertSame(0, $this->service->haPerYear(3214.0, 0));
    }

    public function testRecentDeltaComparesLastThreeYearsToThePriorThree(): void
    {
        // prior 3 = 10+10+10 = 30 ; recent 3 = 20+20+20 = 60 → +100%
        $series = [5.0, 5.0, 5.0, 10.0, 10.0, 10.0, 20.0, 20.0, 20.0];
        self::assertSame(100, $this->service->recentDeltaPct($series));
        // Too little history to compare.
        self::assertNull($this->service->recentDeltaPct([1.0, 2.0, 3.0]));
    }

    public function testFormatCoords(): void
    {
        self::assertSame('3.2°S 35.5°E', $this->service->formatCoords(-3.2, 35.49));
    }
}
