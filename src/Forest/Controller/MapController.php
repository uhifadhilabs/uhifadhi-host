<?php

declare(strict_types=1);

namespace App\Forest\Controller;

use App\Forest\Repository\ForestLossYearRepository;
use App\Spatial\Repository\AreaOfInterestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The deforestation map: the NCA boundary (from the Spatial kernel) with per-year
 * forest-loss footprints overlaid. The boundary is small, so it's embedded in the
 * page; the loss layer is larger, so the map fetches it from a GeoJSON endpoint.
 *
 * Lives in Forest (not Spatial) on purpose: Forest may depend on the Spatial
 * kernel, but Spatial must not know about any topic — deptrac enforces it.
 */
final class MapController extends AbstractController
{
    #[Route('/map', name: 'forest_map', methods: ['GET'])]
    public function page(AreaOfInterestRepository $areas, ForestLossYearRepository $loss): Response
    {
        $boundary = ['type' => 'FeatureCollection', 'features' => []];
        $boundaryName = 'Conservation area';
        foreach ($areas->findAll() as $area) {
            $geom = $area->getGeom();
            if ($geom === null) {
                continue;
            }
            $boundaryName = $area->getName() ?? $boundaryName;
            $boundary['features'][] = [
                'type' => 'Feature',
                'properties' => ['name' => $area->getName()],
                'geometry' => json_decode($geom, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        $years = $loss->findBy([], ['year' => 'ASC']);
        $totalLossHa = 0;
        $lossByYear = [];
        $maxLossHa = 0.0;
        foreach ($years as $footprint) {
            $ha = $footprint->getAreaHa() ?? 0.0;
            $totalLossHa += (int) round($ha);
            $maxLossHa = max($maxLossHa, $ha);
            $lossByYear[] = [
                'year' => (int) $footprint->getYear(),
                'ha' => $ha,
                'color' => $this->yearColor((int) $footprint->getYear()),
            ];
        }

        return $this->render('forest/map.html.twig', [
            'boundary' => $boundary,
            'lossByYear' => $lossByYear,
            'maxLossHa' => $maxLossHa,
            'stats' => [
                'totalLossHa' => $totalLossHa,
                'yearFrom' => $years !== [] ? $years[array_key_first($years)]->getYear() : null,
                'yearTo' => $years !== [] ? $years[array_key_last($years)]->getYear() : null,
                'boundaryKm2' => (int) round($areas->totalAreaKm2()),
                'boundaryName' => $boundaryName,
            ],
        ]);
    }

    /**
     * The Hansen YlOrRd year ramp (2001–2023) — kept in sync with the identical
     * stops in assets/controllers/map_controller.js, so the panel's chart bars
     * match the polygons on the map.
     */
    private function yearColor(int $year): string
    {
        /** @var list<array{int, array{int, int, int}}> $stops */
        $stops = [
            [2001, [0xFF, 0xFF, 0xB2]],
            [2008, [0xFE, 0xCC, 0x5C]],
            [2014, [0xFD, 0x8D, 0x3C]],
            [2019, [0xF0, 0x3B, 0x20]],
            [2023, [0xBD, 0x00, 0x26]],
        ];

        if ($year <= $stops[0][0]) {
            return \sprintf('rgb(%d,%d,%d)', ...$stops[0][1]);
        }
        for ($i = 1, $n = \count($stops); $i < $n; ++$i) {
            [$y2, $c2] = $stops[$i];
            if ($year <= $y2) {
                [$y1, $c1] = $stops[$i - 1];
                $t = ($year - $y1) / ($y2 - $y1);
                $mix = static fn (int $k): int => (int) round($c1[$k] + $t * ($c2[$k] - $c1[$k]));

                return \sprintf('rgb(%d,%d,%d)', $mix(0), $mix(1), $mix(2));
            }
        }

        return \sprintf('rgb(%d,%d,%d)', ...$stops[\count($stops) - 1][1]);
    }

    #[Route('/api/forest-loss.geojson', name: 'forest_loss_geojson', methods: ['GET'])]
    public function forestLoss(ForestLossYearRepository $loss): JsonResponse
    {
        $features = [];
        foreach ($loss->findBy([], ['year' => 'ASC']) as $footprint) {
            $geom = $footprint->getGeom();
            if ($geom === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => ['year' => $footprint->getYear(), 'areaHa' => $footprint->getAreaHa()],
                'geometry' => json_decode($geom, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return new JsonResponse(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
