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

use Doctrine\ORM\EntityManagerInterface;
use FundiStadi\GDALBundle\Process\GdalRunner;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Exception\BoundaryImportException;

/**
 * Turns an uploaded boundary in ANY common GIS format into an AreaOfInterest:
 * GeoJSON is read directly; everything else (zipped shapefile, GeoPackage,
 * KML/KMZ, zipped File Geodatabase) goes through ogr2ogr — reading zips via
 * /vsizip, auto-picking the polygon layer, and reprojecting to WGS84.
 *
 * Real-world archives are messy: WDPA's official download is a zip holding the
 * shapefile in a NESTED zip next to CSVs and PDFs. The importer therefore
 * scans candidates — the archive itself first, then every dataset found inside
 * it — and takes the first one with a polygon layer. Users upload exactly what
 * their GIS office (or protectedplanet.net) hands them.
 *
 * GDAL touches files only; the geometry lands through the ORM (the bundle's
 * geometry type).
 */
final class BoundaryImportService
{
    private const array DATASET_EXTENSIONS = ['shp', 'gpkg', 'kml', 'kmz', 'geojson', 'json'];

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
     * Probes every candidate dataset in/under the upload and converts the first
     * one holding a polygon layer to WGS84 GeoJSON.
     */
    private function convertWithOgr(string $path, string $extension): string
    {
        // Uploads arrive as extensionless tmp files, but OGR's drivers route by
        // extension — give the file its real one back.
        $workdir = sys_get_temp_dir().'/boundary-'.bin2hex(random_bytes(4));
        mkdir($workdir);
        $typed = $workdir.'/upload'.('' !== $extension ? '.'.$extension : '');
        copy($path, $typed);

        try {
            $fallback = null;
            foreach ($this->candidateSources($typed, $extension, $workdir) as $sourcePath) {
                $probe = $this->probePolygonLayer($sourcePath);
                if (null === $probe) {
                    continue;
                }
                if ($probe['polygon']) {
                    return $this->toWgs84GeoJson($sourcePath, $probe['layer'], $workdir);
                }
                $fallback ??= [$sourcePath, $probe['layer']];
            }
            if (null !== $fallback) {
                return $this->toWgs84GeoJson($fallback[0], $fallback[1], $workdir);
            }

            throw new BoundaryImportException('The file could not be read as a GIS dataset — no polygon boundary found (supported: GeoJSON, zipped Shapefile, GeoPackage, KML/KMZ, zipped File Geodatabase — nested archives like WDPA downloads are scanned).');
        } finally {
            self::removeDirectory($workdir);
        }
    }

    /**
     * The archive itself first; for zips, then every dataset found inside it —
     * including NESTED zips (the WDPA download shape).
     *
     * @return iterable<string>
     */
    private function candidateSources(string $typed, string $extension, string $workdir): iterable
    {
        if ('zip' !== $extension) {
            yield $typed;

            return;
        }

        yield '/vsizip/'.$typed;

        $extractDir = $workdir.'/extracted';
        $zip = new \ZipArchive();
        if (true !== $zip->open($typed)) {
            return;
        }
        mkdir($extractDir);
        $zip->extractTo($extractDir);
        $zip->close();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $ext = strtolower($entry->getExtension());
            if ($entry->isDir() && 'gdb' === $ext) {
                yield $entry->getPathname();
            } elseif ($entry->isFile() && 'zip' === $ext) {
                yield '/vsizip/'.$entry->getPathname();
            } elseif ($entry->isFile() && \in_array($ext, self::DATASET_EXTENSIONS, true)) {
                yield $entry->getPathname();
            }
        }
    }

    /**
     * @return array{layer: string, polygon: bool}|null null when the source is
     *                                                  unreadable or has no geometry layer
     */
    private function probePolygonLayer(string $source): ?array
    {
        try {
            $info = json_decode($this->gdal->run(['ogrinfo', '-json', '-ro', $source]), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\RuntimeException|\JsonException) {
            return null;
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
                return ['layer' => $layer['name'], 'polygon' => true];
            }
            $fallback ??= ['layer' => $layer['name'], 'polygon' => false];
        }

        return $fallback;
    }

    private function toWgs84GeoJson(string $source, string $layer, string $workdir): string
    {
        $out = $workdir.'/converted.geojson';
        try {
            $this->gdal->run(['ogr2ogr', '-f', 'GeoJSON', '-t_srs', 'EPSG:4326', $out, $source, $layer]);
        } catch (\RuntimeException $e) {
            throw new BoundaryImportException('The boundary could not be converted — is the dataset valid?', previous: $e);
        }

        return (string) file_get_contents($out);
    }

    private static function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }
}
