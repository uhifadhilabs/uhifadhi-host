<?php

declare(strict_types=1);

namespace Uhifadhi\Seeding\Command;

use Uhifadhi\Seeding\Service\AreaSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds the demo Area (AreaOfInterest) with a FIXED uuid so config that addresses
 * it — the uhakiki campaign's area_ref — resolves after every wipe. Idempotent:
 * re-running is a no-op once the area exists. Part of the app:seed:* family.
 */
#[AsCommand(
    name: 'app:seed:area',
    description: 'Seed the demo Ngorongoro area (fixed uuid) from a GeoJSON boundary.',
)]
final class SeedAreaCommand extends Command
{
    /** Kept in sync with config/packages/uhakiki.yaml (nca_bomas.area_ref). */
    private const string NCA_UUID = 'd0ce9044-b57d-45f7-b615-69b0e38bd271';
    private const string NCA_NAME = 'Ngorongoro Conservation Area';

    public function __construct(
        private readonly AreaSeeder $seeder,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uuid', null, InputOption::VALUE_REQUIRED, 'Fixed uuid for the area', self::NCA_UUID)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Area name', self::NCA_NAME)
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'GeoJSON boundary file', $this->projectDir.'/fixtures/uhakiki/ngorongoro-nca.geojson');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uuid = (string) $input->getOption('uuid');
        $name = (string) $input->getOption('name');
        $file = (string) $input->getOption('file');

        try {
            [$area, $created] = $this->seeder->ensureFromGeoJsonFile($uuid, $name, $file);
        } catch (\RuntimeException|\InvalidArgumentException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%s area "%s" (%s).',
            $created ? 'Created' : 'Already present:',
            $area->getName() ?? $name,
            $area->getUuidString() ?? $uuid,
        ));

        return Command::SUCCESS;
    }
}
