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

namespace Uhifadhi\Telemetry\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Telemetry\Store\CaptureStore;

/**
 * Retention. Telemetry is a monitor, not an archive: this drops captures older
 * than a TTL (default from telemetry.retention_days, overridable with --days) so
 * the telemetry database stays a live picture rather than an ever-growing log.
 *
 * Run it on a schedule against production (cron / Kamal). It uses the same
 * {@see CaptureStore} port as everything else, so it prunes whichever sink is
 * active without knowing which one that is.
 */
#[AsCommand(
    name: 'telemetry:prune',
    description: 'Drop API captures older than the retention window.',
)]
final class PruneCapturesCommand extends Command
{
    public function __construct(
        private readonly CaptureStore $store,
        private readonly int $defaultRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention window in days; captures older than this are dropped.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOption = $input->getOption('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : $this->defaultRetentionDays;
        if ($days < 0) {
            $io->error('Retention window (--days) must not be negative.');

            return Command::FAILURE;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(\sprintf('-%d days', $days));
        $removed = $this->store->prune($cutoff);

        $io->success(\sprintf('Pruned %d capture(s) older than %d day(s) (before %s).', $removed, $days, $cutoff->format('Y-m-d H:i')));

        return Command::SUCCESS;
    }
}
