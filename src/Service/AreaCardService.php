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

namespace Uhifadhi\Service;

/**
 * Pure, view-model computations for the areas register — the metrics the design's
 * register shows that aren't stored columns: the geographic envelope/centroid of a
 * boundary, a real Esri satellite thumbnail for it, hectares-per-year, and the
 * recent-vs-prior loss delta. Stateless so it unit-tests without a kernel.
 */
final class AreaCardService
{
    private const ESRI_EXPORT = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/export';

    /**
     * Bounding box [minLon, minLat, maxLon, maxLat] of a GeoJSON geometry string.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bounds(string $geoJson): array
    {
        $geom = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        $minLon = $minLat = \PHP_FLOAT_MAX;
        $maxLon = $maxLat = -\PHP_FLOAT_MAX;
        $walk = static function (array $node) use (&$walk, &$minLon, &$minLat, &$maxLon, &$maxLat): void {
            // A coordinate pair is [lon, lat] of two numbers; anything else is a nesting level.
            if (2 <= \count($node) && is_numeric($node[0] ?? null) && is_numeric($node[1] ?? null) && !\is_array($node[0])) {
                $lon = (float) $node[0];
                $lat = (float) $node[1];
                $minLon = min($minLon, $lon);
                $maxLon = max($maxLon, $lon);
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);

                return;
            }
            foreach ($node as $child) {
                if (\is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($geom['coordinates'] ?? []);

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Centre of a bbox as [lon, lat].
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds
     *
     * @return array{0: float, 1: float}
     */
    public function centroid(array $bounds): array
    {
        return [($bounds[0] + $bounds[2]) / 2, ($bounds[1] + $bounds[3]) / 2];
    }

    /**
     * A real Esri World Imagery thumbnail for the boundary — the bbox is padded and
     * fitted to the requested aspect so the export isn't distorted.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    public function thumbnailUrl(array $bounds, int $w = 160, int $h = 120): string
    {
        [$cx, $cy] = $this->centroid($bounds);
        $hw = max(($bounds[2] - $bounds[0]) / 2, 0.01) * 1.12;
        $hh = max(($bounds[3] - $bounds[1]) / 2, 0.01) * 1.12;
        $aspect = $w / $h;
        if ($hw / $hh < $aspect) {
            $hw = $hh * $aspect;
        } else {
            $hh = $hw / $aspect;
        }
        $bbox = \sprintf('%.5f,%.5f,%.5f,%.5f', $cx - $hw, $cy - $hh, $cx + $hw, $cy + $hh);

        return self::ESRI_EXPORT.'?'.http_build_query([
            'bbox' => $bbox,
            'bboxSR' => 4326,
            'imageSR' => 3857,
            'size' => $w.','.$h,
            'format' => 'jpg',
            'f' => 'image',
        ]);
    }

    /** Mean hectares lost per year over the series span. */
    public function haPerYear(float $totalHa, int $spanYears): int
    {
        return $spanYears > 0 ? (int) round($totalHa / $spanYears) : 0;
    }

    /**
     * Recent-vs-prior loss delta as a percentage: the last 3 years against the 3
     * before them. Null when there isn't enough history to compare.
     *
     * @param list<float> $series ha per year, oldest → newest
     */
    public function recentDeltaPct(array $series): ?int
    {
        $n = \count($series);
        if ($n < 6) {
            return null;
        }
        $recent = array_sum(\array_slice($series, -3));
        $prior = array_sum(\array_slice($series, -6, 3));
        if ($prior <= 0.0) {
            return null;
        }

        return (int) round(($recent - $prior) / $prior * 100);
    }

    /** Human coordinate label, e.g. "3.2°S 35.5°E". */
    public function formatCoords(float $lat, float $lon): string
    {
        return \sprintf(
            '%.1f°%s %.1f°%s',
            abs($lat), $lat >= 0 ? 'N' : 'S',
            abs($lon), $lon >= 0 ? 'E' : 'W',
        );
    }
}
