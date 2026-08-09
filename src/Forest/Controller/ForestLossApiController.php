<?php

declare(strict_types=1);

namespace App\Forest\Controller;

use App\Forest\Repository\ForestLossYearRepository;
use App\Spatial\Repository\AreaOfInterestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-area forest-loss GeoJSON — the data endpoint the dashboard map fetches.
 * Geometry comes from the bundle's geometry type already as GeoJSON, so each
 * row decodes straight into a feature.
 */
final class ForestLossApiController extends AbstractController
{
    #[Route('/api/areas/{id}/forest-loss.geojson', name: 'forest_loss_geojson', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(int $id, AreaOfInterestRepository $areas, ForestLossYearRepository $loss): JsonResponse
    {
        if (null === $areas->find($id)) {
            throw $this->createNotFoundException(\sprintf('Area %d not found.', $id));
        }

        $features = [];
        foreach ($loss->findBy(['aoi' => $id], ['year' => 'ASC']) as $footprint) {
            $geom = $footprint->getGeom();
            if (null === $geom) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => ['year' => $footprint->getYear(), 'areaHa' => $footprint->getAreaHa()],
                'geometry' => json_decode($geom, true, 512, \JSON_THROW_ON_ERROR),
            ];
        }

        return new JsonResponse(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
