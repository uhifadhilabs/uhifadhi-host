<?php

declare(strict_types=1);

namespace Uhifadhi\Statistics\Command;

use Uhifadhi\Spatial\Repository\AreaOfInterestRepository;
use Uhifadhi\Statistics\Service\SynthesisService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Operator convenience: derive an area's Q6 synthesis right now, inline. */
#[AsCommand(
    name: 'app:statistics:refresh',
    description: 'Re-derive the statistics module\'s synthesis from the other modules\' datasets.',
)]
final class RefreshStatisticsCommand extends Command
{
    public function __construct(
        private readonly AreaOfInterestRepository $areas,
        private readonly SynthesisService $synthesis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('aoi', InputArgument::REQUIRED, 'AreaOfInterest id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $area = $this->areas->find((int) $input->getArgument('aoi'));
        if (null === $area) {
            $io->error('No such area.');

            return Command::FAILURE;
        }

        $this->synthesis->refresh($area);
        $synthesis = $this->synthesis->indicators($area);
        $io->table(['module', 'indicator', 'value', 'unit', 'source'], $synthesis);
        $io->success(\sprintf('Synthesis refreshed: %d indicator(s) + provenance stored.', \count($synthesis)));

        return Command::SUCCESS;
    }
}
