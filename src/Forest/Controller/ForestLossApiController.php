<?php

declare(strict_types=1);

namespace App\Forest\Controller;

use App\Forest\Repository\ForestLossYearRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Per-area forest-loss GeoJSON — the data endpoint the dashboard map fetches.
 * Geometry comes from the bundle's geometry type already as GeoJSON, so each
 * row decodes straight into a feature. The area is addressed by UUID.
 */
final class ForestLossApiController extends AbstractController
{
    #[Route('/api/areas/{uuid}/forest-loss.geojson', name: 'forest_loss_geojson', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        ForestLossYearRepository $loss,
    ): JsonResponse {
        $features = [];
        foreach ($loss->findBy(['aoi' => $area], ['year' => 'ASC']) as $footprint) {
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
