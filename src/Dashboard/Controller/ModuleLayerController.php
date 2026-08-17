<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\ModuleFeatureRepository;
use App\Spatial\Entity\AreaOfInterest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A module's dissolved spatial layer as GeoJSON — the data endpoint every module map (and the area hub's loss layer) fetches. Each {@see \App\Ingestion\Entity\ModuleFeature}
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

    /**
     * A module's raster layer (e.g. an NDVI or suitability surface) as a PNG for the Leaflet
     * ImageOverlay — decoded from the dataset's inline base64 payload. 404 when absent.
     */
    #[Route(
        '/api/areas/{uuid}/{module}/{key}.png',
        name: 'module_layer_raster',
        requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+', 'key' => '[a-z0-9_]+'],
        methods: ['GET'],
    )]
    #[IsGranted('module.view')]
    public function raster(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $key,
        DatasetRepository $datasets,
    ): Response {
        $dataset = $datasets->findOneFor($area, $module, $key);
        $payload = $dataset?->getPayload();
        if (null === $dataset || DatasetKind::Raster !== $dataset->getKind() || null === $payload) {
            throw $this->createNotFoundException('No raster layer.');
        }
        $binary = base64_decode($payload, true);
        if (false === $binary) {
            throw $this->createNotFoundException('Undecodable raster payload.');
        }

        return new Response($binary, headers: ['Content-Type' => 'image/png']);
    }

    /**
     * A module's tabular dataset as a CSV download — plain Symfony streaming + native fputcsv,
     * no export library. A real link, so the browser downloads it without any JS involved.
     */
    #[Route(
        '/api/areas/{uuid}/{module}/{key}.csv',
        name: 'module_dataset_csv',
        requirements: ['uuid' => Requirement::UUID, 'module' => '[a-z]+', 'key' => '[a-z0-9_]+'],
        methods: ['GET'],
    )]
    #[IsGranted('module.view')]
    public function csv(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $module,
        string $key,
        DatasetRepository $datasets,
    ): Response {
        $dataset = $datasets->findOneFor($area, $module, $key);
        $rows = $dataset?->getRows();
        if (null === $dataset || !\is_array($rows)) {
            throw $this->createNotFoundException('No tabular dataset.');
        }
        $columns = $dataset->getColumns() ?? [];

        $response = new StreamedResponse(static function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            \assert(false !== $out);
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, \is_array($row) ? $row : [$row], escape: '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s.csv"', $key));

        return $response;
    }
}
