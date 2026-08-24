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
use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Service\GeoJsonNormalizerService;
use Uhifadhi\Service\ZoneFeatureParser;

/**
 * The pure half of the zone import: a GeoJSON FeatureCollection turned into named
 * MultiPolygon candidates, with every shape problem reported against the feature it
 * came from. No database, no persistence — the spatial invariant is checked later.
 */
final class ZoneFeatureParserTest extends TestCase
{
    private function parser(): ZoneFeatureParser
    {
        return new ZoneFeatureParser(new GeoJsonNormalizerService());
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<string, mixed>
     */
    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(?string $name, float $x = 0.0): array
    {
        return [
            'type' => 'Feature',
            'properties' => null === $name ? [] : ['name' => $name],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[$x, 0.0], [$x + 1, 0.0], [$x + 1, 1.0], [$x, 1.0], [$x, 0.0]]],
            ],
        ];
    }

    public function testEachFeatureBecomesANamedMultiPolygonCandidate(): void
    {
        $zones = $this->parser()->parse($this->collection([
            $this->feature('North'),
            $this->feature('South', 2.0),
        ]));

        self::assertSame(['North', 'South'], array_map(static fn ($z): string => $z->name, $zones));
        /** @var array{type: string, coordinates: list<mixed>} $decoded */
        $decoded = json_decode($zones[0]->geomJson, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('MultiPolygon', $decoded['type']);
        self::assertCount(1, $decoded['coordinates'], 'a Polygon feature becomes a one-polygon MultiPolygon');
    }

    public function testAFeatureWithoutANameIsRejectedByItsPosition(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/feature #2.*name/i');

        $this->parser()->parse($this->collection([$this->feature('North'), $this->feature(null, 2.0)]));
    }

    public function testADuplicateNameInsideTheFileIsRejected(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/North/');

        $this->parser()->parse($this->collection([$this->feature('North'), $this->feature('North', 2.0)]));
    }

    public function testANonPolygonalFeatureIsRejectedByName(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/Ridge/');

        $this->parser()->parse($this->collection([[
            'type' => 'Feature',
            'properties' => ['name' => 'Ridge'],
            'geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
        ]]));
    }

    public function testOnlyAFeatureCollectionIsAccepted(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/FeatureCollection/');

        $this->parser()->parse(['type' => 'Polygon', 'coordinates' => []]);
    }

    public function testAnEmptyCollectionIsRejected(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/no features/i');

        $this->parser()->parse($this->collection([]));
    }

    public function testTheSingleZonePathNormalisesAGeometryUnderAGivenName(): void
    {
        $zone = $this->parser()->parseOne('Drawn', [
            'type' => 'Polygon',
            'coordinates' => [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]],
        ]);

        self::assertSame('Drawn', $zone->name);
        self::assertStringContainsString('MultiPolygon', $zone->geomJson);
    }

    public function testTheSingleZonePathRejectsABlankName(): void
    {
        $this->expectException(ZoneImportException::class);

        $this->parser()->parseOne('  ', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]);
    }
}
