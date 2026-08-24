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
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Exception\ZoneOverlapException;
use Uhifadhi\Model\ZoneFeature;
use Uhifadhi\Repository\ZoneRepository;

/**
 * Loads a whole zoning scheme — one GeoJSON FeatureCollection, one zone per feature —
 * into an area. ALL OR NOTHING: names and geometries are checked against the zones
 * already stored AND against the other features of the same file before a single row is
 * written, so a file with one bad zone leaves the area untouched and the message says
 * which zone, why, and (for an overlap) which zone it collided with.
 */
final class ZoneImportService
{
    public function __construct(
        private readonly ZoneFeatureParser $parser,
        private readonly ZoneService $zones,
        private readonly ZoneRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Zone>
     *
     * @throws ZoneImportException
     */
    public function importFile(AreaOfInterest $area, string $file): array
    {
        $raw = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if (false === $raw) {
            throw new ZoneImportException(\sprintf('Cannot read the zones file: %s', $file));
        }

        try {
            $document = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ZoneImportException('The zones file is not valid JSON: '.$e->getMessage(), previous: $e);
        }
        if (!\is_array($document)) {
            throw new ZoneImportException('The zones file must contain a GeoJSON FeatureCollection.');
        }

        return $this->importFeatureCollection($area, $document);
    }

    /**
     * @param array<array-key, mixed> $document a decoded GeoJSON FeatureCollection
     *
     * @return list<Zone> the imported zones, in file order
     *
     * @throws ZoneImportException
     */
    public function importFeatureCollection(AreaOfInterest $area, array $document): array
    {
        $candidates = $this->parser->parse($document);

        foreach ($candidates as $candidate) {
            $this->assertNameIsFree($area, $candidate->name);
            $this->assertFitsExistingZones($area, $candidate);
        }
        $this->assertCandidatesFitEachOther($candidates);

        $zones = [];
        foreach ($candidates as $candidate) {
            $zone = new Zone()->setArea($area)->setName($candidate->name)->setGeom($candidate->geomJson);
            $this->em->persist($zone);
            $zones[] = $zone;
        }
        // One flush for the whole file: everything checked, everything written together.
        $this->em->flush();

        return $zones;
    }

    /**
     * The single-zone path the draw-on-map flow will use: a name and one polygonal
     * geometry, held to the same invariant.
     *
     * @param array<array-key, mixed> $geometry
     *
     * @throws ZoneImportException
     */
    public function importZone(AreaOfInterest $area, string $name, array $geometry): Zone
    {
        $candidate = $this->parser->parseOne($name, $geometry);
        $this->assertNameIsFree($area, $candidate->name);

        try {
            return $this->zones->create($area, $candidate->name, $candidate->geomJson);
        } catch (ZoneOverlapException $e) {
            throw new ZoneImportException($e->getMessage(), previous: $e);
        }
    }

    private function assertNameIsFree(AreaOfInterest $area, string $name): void
    {
        if (null !== $this->repository->findOneForName($area, $name)) {
            throw new ZoneImportException(\sprintf('The area already has a zone named "%s" — zone names are unique within an area.', $name));
        }
    }

    private function assertFitsExistingZones(AreaOfInterest $area, ZoneFeature $candidate): void
    {
        try {
            $this->zones->assertFits($area, $candidate->name, $candidate->geomJson);
        } catch (ZoneOverlapException $e) {
            throw new ZoneImportException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param list<ZoneFeature> $candidates
     */
    private function assertCandidatesFitEachOther(array $candidates): void
    {
        $count = \count($candidates);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($this->zones->conflicts($candidates[$i]->geomJson, $candidates[$j]->geomJson)) {
                    throw new ZoneImportException(ZoneOverlapException::betweenNames($candidates[$j]->name, $candidates[$i]->name)->getMessage());
                }
            }
        }
    }
}
