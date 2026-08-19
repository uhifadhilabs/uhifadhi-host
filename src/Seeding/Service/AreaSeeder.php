<?php

declare(strict_types=1);

namespace Uhifadhi\Seeding\Service;

use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Uhifadhi\Spatial\Repository\AreaOfInterestRepository;
use Uhifadhi\Spatial\Service\GeoJsonNormalizerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotently ensures a demo AreaOfInterest exists with a FIXED uuid, so config
 * that addresses an area by uuid — e.g. a uhakiki campaign's `area_ref` — keeps
 * resolving after every wipe/reseed instead of chasing a freshly generated uuid.
 * Reused by the app:seed:area command and by fixtures.
 */
final class AreaSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AreaOfInterestRepository $areas,
        private readonly GeoJsonNormalizerService $normalizer,
    ) {
    }

    /**
     * @return array{AreaOfInterest, bool} the area and whether it was just created
     */
    public function ensureFromGeoJsonFile(string $uuid, string $name, string $file, string $source = 'seed'): array
    {
        $id = Uuid::fromString($uuid);
        $existing = $this->areas->findOneBy(['uuid' => $id]);
        if (null !== $existing) {
            return [$existing, false];
        }

        $raw = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Cannot read GeoJSON file: %s', $file));
        }
        $doc = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($doc)) {
            throw new \InvalidArgumentException('GeoJSON root must be an object.');
        }
        $polygons = $this->normalizer->toMultiPolygonCoordinates($doc);
        if ([] === $polygons) {
            throw new \InvalidArgumentException('No Polygon/MultiPolygon geometry found in the file.');
        }

        // Fixed uuid set before persist — UuidTrait::generateUuid() only fills a
        // null uuid, so this survives the PrePersist callback.
        $area = (new AreaOfInterest())
            ->setUuid($id)
            ->setName($name)
            ->setGeom(json_encode(['type' => 'MultiPolygon', 'coordinates' => $polygons], \JSON_THROW_ON_ERROR))
            ->setSource($source);
        $this->em->persist($area);
        $this->em->flush();

        return [$area, true];
    }
}
