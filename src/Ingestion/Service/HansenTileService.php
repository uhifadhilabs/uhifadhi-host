<?php

declare(strict_types=1);

namespace App\Ingestion\Service;

use FundiStadi\GDALBundle\Vsi\VsiCurl;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Hansen Global Forest Change granules: 10°×10° GeoTIFFs named by their
 * TOP-LEFT (NW) corner (00N_030E covers lat 0…−10, lon 30…40), served from a
 * public bucket. Sources are /vsicurl/ URIs, so GDAL reads only the needed
 * window via HTTP range requests.
 */
#[AsAlias(TileSourceInterface::class)]
final class HansenTileService implements TileSourceInterface
{
    private const string BASE = 'https://storage.googleapis.com/earthenginepartners-hansen';
    private const string LAYER = 'lossyear';

    public function sources(float $minX, float $minY, float $maxX, float $maxY, string $version): array
    {
        if ($minX > $maxX || $minY > $maxY) {
            throw new \InvalidArgumentException('Inverted bounding box (min > max).');
        }

        $sources = [];
        // Tile tops: every 10° band overlapping [minY, maxY], identified by its top edge.
        for ($top = (int) (floor($minY / 10) * 10) + 10; $top - 10 < $maxY; $top += 10) {
            for ($left = (int) (floor($minX / 10) * 10); $left < $maxX; $left += 10) {
                $sources[] = VsiCurl::wrap(\sprintf(
                    '%s/%s/Hansen_%s_%s_%s.tif',
                    self::BASE,
                    $version,
                    $version,
                    self::LAYER,
                    $this->granuleName($top, $left),
                ));
            }
        }

        return $sources;
    }

    private function granuleName(int $latTop, int $lonLeft): string
    {
        return \sprintf(
            '%02d%s_%03d%s',
            abs($latTop),
            $latTop >= 0 ? 'N' : 'S',
            abs($lonLeft),
            $lonLeft >= 0 ? 'E' : 'W',
        );
    }
}
