<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Service\HansenTileService;
use PHPUnit\Framework\TestCase;

/**
 * Hansen GFC ships 10°×10° granules named by their TOP-LEFT (NW) corner:
 * 00N_030E covers lat 0…−10, lon 30…40. The service maps an AOI bbox to the
 * /vsicurl/ source URIs of every overlapping granule.
 */
final class HansenTileServiceTest extends TestCase
{
    private HansenTileService $tiles;

    protected function setUp(): void
    {
        $this->tiles = new HansenTileService();
    }

    public function testTheNcaBboxFallsInASingleTile(): void
    {
        // Ngorongoro: lon 34.88…35.97, lat −3.61…−2.50 → granule 00N_030E.
        $sources = $this->tiles->sources(34.88, -3.61, 35.97, -2.50, 'GFC-2023-v1.11');

        self::assertSame(
            ['/vsicurl/https://storage.googleapis.com/earthenginepartners-hansen/GFC-2023-v1.11/Hansen_GFC-2023-v1.11_lossyear_00N_030E.tif'],
            $sources,
        );
    }

    public function testABboxSpanningATileCornerYieldsFourTiles(): void
    {
        $sources = $this->tiles->sources(38.5, -1.5, 41.5, 1.5, 'GFC-2023-v1.11');

        $names = array_map(
            static fn (string $uri): string => substr($uri, -12, 8),
            $sources,
        );
        self::assertSame(['00N_030E', '00N_040E', '10N_030E', '10N_040E'], $names);
    }

    public function testNamingAcrossHemispheres(): void
    {
        // Top edge −10 → "10S"; western longitudes → e.g. "070W".
        $south = $this->tiles->sources(-69.5, -12.5, -69.4, -12.4, 'GFC-2023-v1.11');
        self::assertStringContainsString('_lossyear_10S_070W.tif', $south[0]);

        // Top edge +10 → "10N".
        $north = $this->tiles->sources(30.5, 5.5, 30.6, 5.6, 'GFC-2023-v1.11');
        self::assertStringContainsString('_lossyear_10N_030E.tif', $north[0]);
    }

    public function testAnInvertedBboxIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tiles->sources(36.0, -3.0, 35.0, -2.0, 'GFC-2023-v1.11');
    }
}
