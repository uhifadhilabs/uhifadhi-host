<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Message\RunModuleIngestion;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Repository\AreaOfInterestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a module's ingestion for an area (by uuid, id or name) through the geoprocessing engine and
 * lists the datasets it produced. Dispatched synchronously here so the operator sees the result inline
 * (the web UI routes the same message async); the engine must be reachable at ENGINE_BASE_URI — the
 * local dev trigger for the whole app → engine → dataset loop.
 */
#[AsCommand(
    name: 'app:module:ingest',
    description: 'Run a module ingestion for an area via the engine and land its datasets.',
)]
final class RunModuleIngestionCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AreaOfInterestRepository $areas,
        private readonly DatasetRepository $datasets,
        private readonly DatasetRunRepository $runs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('aoi', InputArgument::REQUIRED, 'AreaOfInterest uuid, id or name')
            ->addArgument('module', InputArgument::REQUIRED, 'module slug, e.g. landcover')
            ->addOption('res', null, InputOption::VALUE_REQUIRED, 'stats resolution in metres', '30')
            ->addOption('detail', null, InputOption::VALUE_REQUIRED, 'map-layer coarseness factor (×res): lower = finer, heavier', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $aoiArg = $input->getArgument('aoi');
        $module = $input->getArgument('module');
        $res = $input->getOption('res');
        $detail = $input->getOption('detail');
        if (!\is_string($aoiArg) || !\is_string($module) || !is_numeric($res) || !is_numeric($detail)) {
            $io->error('aoi and module must be strings, --res and --detail numeric.');

            return Command::FAILURE;
        }

        $area = $this->resolveArea($aoiArg);
        if (null === $area || null === $area->getId()) {
            $io->error(\sprintf('No AreaOfInterest matches "%s".', $aoiArg));

            return Command::FAILURE;
        }

        $io->section(\sprintf('Ingesting "%s" for "%s" (AOI %d) via the engine', $module, (string) $area->getName(), $area->getId()));

        // Sync transport so the run happens inline and the operator sees its datasets; the web UI
        // routes the same message to the async worker unchanged.
        $this->bus->dispatch(
            new RunModuleIngestion($area->getId(), $module, ['res_m' => (float) $res, 'display_factor' => (int) $detail]),
            [new TransportNamesStamp(['sync'])],
        );

        $run = $this->runs->findOneBy(['dataset' => $module], ['id' => 'DESC']);
        if (null !== $run && DatasetRun::STATUS_FAILED === $run->getStatus()) {
            $io->error(\sprintf('Run failed: %s', (string) $run->getError()));

            return Command::FAILURE;
        }

        $rows = array_map(
            static fn ($dataset): array => [
                (string) $dataset->getKey(),
                $dataset->getKind()->value,
                null !== $dataset->getRows() ? \sprintf('%d rows', \count($dataset->getRows())) : (string) $dataset->getPath(),
            ],
            $this->datasets->forModule($area, $module),
        );

        if ([] === $rows) {
            $io->warning('Run finished but produced no datasets.');

            return Command::SUCCESS;
        }

        $io->table(['Dataset key', 'Kind', 'Payload'], $rows);
        $io->success(\sprintf('%d dataset(s) landed for "%s".', \count($rows), $module));

        return Command::SUCCESS;
    }

    private function resolveArea(string $arg): ?AreaOfInterest
    {
        if (Uuid::isValid($arg)) {
            return $this->areas->findOneBy(['uuid' => Uuid::fromString($arg)]);
        }
        if (ctype_digit($arg)) {
            return $this->areas->find((int) $arg);
        }

        return $this->areas->findOneBy(['name' => $arg]);
    }
}
