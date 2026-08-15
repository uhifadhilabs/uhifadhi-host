<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Ingestion\Repository\ModuleFeatureRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A module's dissolved spatial layer as GeoJSON — the data endpoint the module map fetches, the
 * generic twin of {@see \App\Forest\Controller\ForestLossApiController}. Each {@see \App\Ingestion\Entity\ModuleFeature}
 * carries its geometry already as GeoJSON (the PostGIS bundle's type), so a row decodes straight into
 * a feature; the label travels as a property so the map can colour by class. Addressed by area UUID.
 */
final class ModuleLayerController extends AbstractController
{
    #[Route(
        '/api/areas/{uuid}/{module}/{key}.geojson',
        name: 'module_layer_geojson',
        requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+', 'key' => '[a-z0-9_]+'],
        methods: ['GET'],
    )]
    #[IsGranted('module.view')]
    public function __invoke(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $key,
        ModuleFeatureRepository $features,
    ): JsonResponse {
        $collection = [];
        foreach ($features->forLayer($area, $module, $key) as $feature) {
            $geom = $feature->getGeom();
            if (null === $geom) {
                continue;
            }
            $collection[] = [
                'type' => 'Feature',
                'properties' => ['label' => $feature->getLabel()],
                'geometry' => json_decode($geom, true, 512, \JSON_THROW_ON_ERROR),
            ];
        }

        return new JsonResponse(['type' => 'FeatureCollection', 'features' => $collection]);
    }
}
