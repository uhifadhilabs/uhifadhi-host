<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Message\IngestForestLoss;
use App\Ingestion\Repository\DatasetRunRepository;
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

/**
 * Runs the Hansen GFC forest-loss ingestion for an area of interest (by id or
 * name). The message is unrouted, so it executes synchronously here; routing it
 * to an async transport later needs no change to this command.
 */
#[AsCommand(
    name: 'app:forest:ingest',
    description: 'Run the Forest module loss ingestion for an area (source: Hansen GFC) into forest_loss_year.',
)]
final class IngestForestLossCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AreaOfInterestRepository $areas,
        private readonly DatasetRunRepository $runs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('aoi', InputArgument::REQUIRED, 'AreaOfInterest id or name')
            ->addOption('gfc-version', null, InputOption::VALUE_REQUIRED, 'Hansen GFC release', 'GFC-2023-v1.11')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'source label written to forest_loss_year', 'hansen')
            ->addOption('simplify', null, InputOption::VALUE_REQUIRED, 'simplification tolerance in degrees', '0.0003');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $aoiArgument = $input->getArgument('aoi');
        $version = $input->getOption('gfc-version');
        $source = $input->getOption('source');
        $simplify = $input->getOption('simplify');
        if (!\is_string($aoiArgument) || !\is_string($version) || !\is_string($source) || !is_numeric($simplify)) {
            $io->error('aoi must be a string, --gfc-version/--source strings, --simplify numeric.');

            return Command::FAILURE;
        }

        $aoi = ctype_digit($aoiArgument)
            ? $this->areas->find((int) $aoiArgument)
            : $this->areas->findOneBy(['name' => $aoiArgument]);
        if (null === $aoi || null === $aoi->getId()) {
            $io->error(\sprintf('No AreaOfInterest matches "%s".', $aoiArgument));

            return Command::FAILURE;
        }

        $io->section(\sprintf('Ingesting %s for "%s" (AOI %d)', $version, (string) $aoi->getName(), $aoi->getId()));

        // The message is routed async for the web UI; the CLI overrides to the
        // sync transport so the operator sees the run (and its report) inline.
        $this->bus->dispatch(new IngestForestLoss(
            aoiId: $aoi->getId(),
            version: $version,
            source: $source,
            simplifyDegrees: (float) $simplify,
        ), [new TransportNamesStamp(['sync'])]);

        $run = $this->runs->findOneBy(['dataset' => 'hansen_gfc_lossyear'], ['id' => 'DESC']);
        $report = $run?->getReport();
        if (null === $report) {
            $io->warning('Run finished but no report was recorded.');

            return Command::SUCCESS;
        }

        /** @var array<string, float> $byYear */
        $byYear = \is_array($report['byYearHa'] ?? null) ? $report['byYearHa'] : [];
        $io->table(
            ['Year', 'Loss (ha)'],
            array_map(static fn (string $year, float $ha): array => [$year, number_format($ha)], array_keys($byYear), $byYear),
        );
        $years = $report['years'] ?? null;
        $total = $report['totalHa'] ?? null;
        $io->success(\sprintf(
            '%s year(s), %s ha total — DatasetRun #%d.',
            is_scalar($years) ? (string) $years : '?',
            number_format(is_numeric($total) ? (float) $total : 0.0),
            (int) $run->getId(),
        ));

        return Command::SUCCESS;
    }
}
