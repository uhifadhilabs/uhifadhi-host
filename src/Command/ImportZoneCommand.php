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

namespace Uhifadhi\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Zone;
use Uhifadhi\Exception\ZoneImportException;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\ZoneRepository;
use Uhifadhi\Service\ZoneImportService;

/**
 * Loads a zoning scheme into an area: one GeoJSON FeatureCollection, one zone per
 * feature, named by each feature's `name` property. The area is addressed by uuid — the
 * public handle, never the sequential id. The import is all-or-nothing: a rejected file
 * leaves the area exactly as it was and the error names the zone that caused it.
 */
#[AsCommand(
    name: 'app:zone:import',
    description: 'Import zones (a named polygon each) into an area from a GeoJSON FeatureCollection.',
)]
final class ImportZoneCommand extends Command
{
    public function __construct(
        private readonly AreaOfInterestRepository $areas,
        private readonly ZoneRepository $zones,
        private readonly ZoneImportService $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Uuid of the area the zones subdivide')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a GeoJSON FeatureCollection — one feature per zone, each with a "name" property');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $areaUuid = $input->getOption('area');
        $file = $input->getOption('file');
        if (!\is_string($areaUuid) || '' === $areaUuid) {
            $io->error('--area is required: the uuid of the area to zone.');

            return Command::FAILURE;
        }
        if (!\is_string($file) || '' === $file) {
            $io->error('--file is required: a GeoJSON FeatureCollection of zones.');

            return Command::FAILURE;
        }

        if (!Uuid::isValid($areaUuid)) {
            $io->error(\sprintf('"%s" is not a valid uuid.', $areaUuid));

            return Command::FAILURE;
        }
        $area = $this->areas->findOneBy(['uuid' => Uuid::fromString($areaUuid)]);
        if (null === $area) {
            $io->error(\sprintf('No area with uuid %s.', $areaUuid));

            return Command::FAILURE;
        }

        try {
            $zones = $this->importer->importFile($area, $file);
        } catch (ZoneImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Zone', 'Uuid', 'Area (km²)'],
            array_map(fn (Zone $zone): array => [
                $zone->getName() ?? '',
                $zone->getUuidString() ?? '',
                number_format($this->zones->stAreaKm2(['id' => $zone->getId()]), 1),
            ], $zones),
        );
        $io->success(\sprintf('Imported %d zone(s) into "%s".', \count($zones), $area->getName() ?? $areaUuid));

        return Command::SUCCESS;
    }
}
