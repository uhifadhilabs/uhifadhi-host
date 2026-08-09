<?php

declare(strict_types=1);

namespace App\Spatial\Service;

use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Exception\BoundaryImportException;
use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalRunner;

/**
 * Turns an uploaded boundary in ANY common GIS format into an AreaOfInterest:
 * GeoJSON is read directly; everything else (zipped shapefile, GeoPackage,
 * KML/KMZ, zipped File Geodatabase) goes through ogr2ogr — reading zips in
 * place via /vsizip, auto-picking the polygon layer, and reprojecting to
 * WGS84. This automates exactly the manual WDPA-download procedure: users
 * upload what their GIS office hands them.
 *
 * GDAL touches files only; the geometry lands through the ORM (the bundle's
 * geometry type).
 */
final class BoundaryImportService
{
    public function __construct(
        private readonly GdalRunner $gdal,
        private readonly GeoJsonNormalizerService $normalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string $path         the uploaded file on disk
     * @param string $originalName the client filename (its extension routes the format)
     *
     * @throws BoundaryImportException with a user-facing message
     */
    public function import(string $path, string $originalName, string $areaName, string $source): AreaOfInterest
    {
        $extension = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));

        try {
            $geoJson = \in_array($extension, ['geojson', 'json'], true)
                ? (string) file_get_contents($path)
                : $this->convertWithOgr($path, $extension);

            $document = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($document)) {
                throw new BoundaryImportException('The file did not contain a GeoJSON object.');
            }
            $polygons = $this->normalizer->toMultiPolygonCoordinates($document);
        } catch (\JsonException $e) {
            throw new BoundaryImportException('The file is not valid GeoJSON: '.$e->getMessage(), previous: $e);
        } catch (\InvalidArgumentException $e) {
            throw new BoundaryImportException('No polygon boundary found in the file: '.$e->getMessage(), previous: $e);
        }

        if ([] === $polygons) {
            throw new BoundaryImportException('No polygon boundary found in the file.');
        }

        $aoi = (new AreaOfInterest())
            ->setName($areaName)
            ->setGeom((string) json_encode(['type' => 'MultiPolygon', 'coordinates' => $polygons], \JSON_THROW_ON_ERROR))
            ->setSource($source);
        $this->em->persist($aoi);
        $this->em->flush();

        return $aoi;
    }

    /**
     * ogrinfo finds the polygon layer; ogr2ogr converts it to WGS84 GeoJSON.
     */
    private function convertWithOgr(string $path, string $extension): string
    {
        // Uploads arrive as extensionless tmp files, but OGR's drivers route by
        // extension — give the file its real one back.
        $typed = sys_get_temp_dir().'/boundary-'.bin2hex(random_bytes(4)).('' !== $extension ? '.'.$extension : '');
        copy($path, $typed);

        // Zips (shapefile/gdb archives) are read in place — GDAL's /vsizip.
        $source = 'zip' === $extension ? '/vsizip/'.$typed : $typed;

        $layer = $this->pickPolygonLayer($source);

        $out = tempnam(sys_get_temp_dir(), 'boundary').'.geojson';
        try {
            $this->gdal->run(['ogr2ogr', '-f', 'GeoJSON', '-t_srs', 'EPSG:4326', $out, $source, $layer]);

            return (string) file_get_contents($out);
        } catch (\RuntimeException $e) {
            throw new BoundaryImportException('The file could not be converted — is it a valid GIS dataset?', previous: $e);
        } finally {
            @unlink($out);
            @unlink($typed);
        }
    }

    private function pickPolygonLayer(string $source): string
    {
        try {
            $info = json_decode($this->gdal->run(['ogrinfo', '-json', '-ro', $source]), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\RuntimeException|\JsonException $e) {
            throw new BoundaryImportException('The file could not be read as a GIS dataset (supported: GeoJSON, zipped Shapefile, GeoPackage, KML/KMZ, zipped File Geodatabase).', previous: $e);
        }

        $layers = \is_array($info) && \is_array($info['layers'] ?? null) ? $info['layers'] : [];
        $fallback = null;
        foreach ($layers as $layer) {
            if (!\is_array($layer) || !\is_string($layer['name'] ?? null)) {
                continue;
            }
            $geometryFields = \is_array($layer['geometryFields'] ?? null) ? $layer['geometryFields'] : [];
            if ([] === $geometryFields) {
                continue;
            }
            $type = \is_array($geometryFields[0]) && \is_string($geometryFields[0]['type'] ?? null)
                ? $geometryFields[0]['type'] : '';
            if (str_contains(strtolower($type), 'polygon')) {
                return $layer['name']; // first polygon layer wins (auto-pick)
            }
            $fallback ??= $layer['name'];
        }

        return $fallback ?? throw new BoundaryImportException(
            'The file contains no polygon layer — a boundary must be a Polygon or MultiPolygon dataset.',
        );
    }
}
