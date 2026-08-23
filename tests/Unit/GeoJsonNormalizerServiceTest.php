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
use Uhifadhi\Service\GeoJsonNormalizerService;

final class GeoJsonNormalizerServiceTest extends TestCase
{
    private GeoJsonNormalizerService $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GeoJsonNormalizerService();
    }

    /** @var list<mixed> one square linear ring */
    private const RING = [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]];

    public function testAPolygonBecomesASinglePolygonMultiPolygon(): void
    {
        $coordinates = $this->normalizer->toMultiPolygonCoordinates([
            'type' => 'Polygon',
            'coordinates' => self::RING,
        ]);

        self::assertSame([self::RING], $coordinates);
    }

    public function testAMultiPolygonPassesThrough(): void
    {
        $coordinates = $this->normalizer->toMultiPolygonCoordinates([
            'type' => 'MultiPolygon',
            'coordinates' => [self::RING, self::RING],
        ]);

        self::assertSame([self::RING, self::RING], $coordinates);
    }

    public function testAFeatureIsUnwrappedToItsGeometry(): void
    {
        $coordinates = $this->normalizer->toMultiPolygonCoordinates([
            'type' => 'Feature',
            'properties' => ['name' => 'x'],
            'geometry' => ['type' => 'Polygon', 'coordinates' => self::RING],
        ]);

        self::assertSame([self::RING], $coordinates);
    }

    public function testAFeatureCollectionMergesEveryPolygonIntoOneMultiPolygon(): void
    {
        $coordinates = $this->normalizer->toMultiPolygonCoordinates([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => self::RING]],
                ['type' => 'Feature', 'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [self::RING]]],
            ],
        ]);

        self::assertSame([self::RING, self::RING], $coordinates);
    }

    public function testAMissingTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type/');

        $this->normalizer->toMultiPolygonCoordinates(['coordinates' => self::RING]);
    }

    public function testAPointGeometryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Point/');

        $this->normalizer->toMultiPolygonCoordinates(['type' => 'Point', 'coordinates' => [0, 0]]);
    }

    public function testAFeatureWithoutGeometryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->normalizer->toMultiPolygonCoordinates(['type' => 'Feature', 'geometry' => null]);
    }

    public function testAGeometryWithoutCoordinatesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->normalizer->toMultiPolygonCoordinates(['type' => 'Polygon']);
    }
}
