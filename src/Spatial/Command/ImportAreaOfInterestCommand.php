<?php

declare(strict_types=1);

namespace App\Spatial\Command;

use App\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads a boundary from a GeoJSON file into {@see AreaOfInterest}. Accepts a
 * Geometry, Feature, or FeatureCollection and normalises everything to a single
 * MultiPolygon (WGS84) — the geom column's type. No spatial toolchain required;
 * PostGIS parses the GeoJSON on insert (via fundi-postgis' ST_GeomFromGeoJSON).
 */
#[AsCommand(
    name: 'app:aoi:import',
    description: 'Import an area-of-interest boundary from a GeoJSON file into the Spatial context.',
)]
final class ImportAreaOfInterestCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Human name for the area, e.g. "NCA boundary"')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a GeoJSON file (Geometry, Feature or FeatureCollection)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Provenance label stored with the row', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $file = $input->getArgument('file');
        $source = $input->getOption('source');
        if (!\is_string($name) || !\is_string($file) || !\is_string($source)) {
            $io->error('name and file arguments and --source must be strings.');

            return Command::FAILURE;
        }

        $raw = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            $io->error(\sprintf('Cannot read GeoJSON file: %s', $file));

            return Command::FAILURE;
        }

        try {
            $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($doc)) {
                throw new \InvalidArgumentException('GeoJSON root must be an object.');
            }
            $polygons = $this->collectPolygons($doc);
        } catch (\JsonException $e) {
            $io->error(\sprintf('Invalid JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($polygons === []) {
            $io->error('No Polygon/MultiPolygon geometry found in the file.');

            return Command::FAILURE;
        }

        $multiPolygon = ['type' => 'MultiPolygon', 'coordinates' => $polygons];

        $aoi = (new AreaOfInterest())
            ->setName($name)
            ->setGeom(json_encode($multiPolygon, JSON_THROW_ON_ERROR))
            ->setSource($source);

        $this->em->persist($aoi);
        $this->em->flush();

        $io->success(\sprintf(
            'Imported "%s" (id %d) — %d polygon(s), source "%s".',
            $name,
            (int) $aoi->getId(),
            \count($polygons),
            $source,
        ));

        return Command::SUCCESS;
    }

    /**
     * Flatten any GeoJSON document into a list of Polygon coordinate arrays (each
     * a list of linear rings), so several features/polygons merge into one
     * MultiPolygon.
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<mixed> the `coordinates` value for a MultiPolygon
     */
    private function collectPolygons(array $node): array
    {
        $type = $node['type'] ?? null;
        if (!\is_string($type)) {
            throw new \InvalidArgumentException('GeoJSON object is missing a "type".');
        }

        return match ($type) {
            'FeatureCollection' => $this->fromFeatures($node['features'] ?? null),
            'Feature' => $this->collectPolygons($this->geometryOf($node)),
            'Polygon' => [$this->coordinatesOf($node)],
            'MultiPolygon' => array_values($this->coordinatesOf($node)),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported geometry type "%s" (need Polygon/MultiPolygon).', $type)),
        };
    }

    /**
     * @param mixed $features
     *
     * @return list<mixed>
     */
    private function fromFeatures($features): array
    {
        if (!\is_array($features)) {
            throw new \InvalidArgumentException('FeatureCollection has no "features" array.');
        }
        $polygons = [];
        foreach ($features as $feature) {
            if (\is_array($feature)) {
                array_push($polygons, ...$this->collectPolygons($this->geometryOf($feature)));
            }
        }

        return $polygons;
    }

    /**
     * @param array<array-key, mixed> $feature
     *
     * @return array<array-key, mixed>
     */
    private function geometryOf(array $feature): array
    {
        $geometry = $feature['geometry'] ?? null;
        if (!\is_array($geometry)) {
            throw new \InvalidArgumentException('Feature has no "geometry".');
        }

        return $geometry;
    }

    /**
     * @param array<array-key, mixed> $geometry
     *
     * @return array<array-key, mixed>
     */
    private function coordinatesOf(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            throw new \InvalidArgumentException('Geometry has no "coordinates".');
        }

        return $coordinates;
    }
}
