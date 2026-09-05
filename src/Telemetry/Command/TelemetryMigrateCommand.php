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

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies the Telemetry migrations (migrations-telemetry/) to the SECOND,
 * telemetry-only connection — and nothing else's.
 *
 * WHY A DEDICATED COMMAND rather than `doctrine:migrations:migrate --conn=telemetry`:
 * the migrations bundle builds its DependencyFactory from the DEFAULT entity
 * manager whenever one exists, and then silently ignores `--conn` — so that route
 * would run the telemetry migration against the APP database. The only way to make
 * the bundle command honour a connection is the global `preferred_connection`
 * setting, which would also re-point `migrations:diff` for the whole app. Rather
 * than change app-wide migration behaviour to serve telemetry, this command builds
 * a standalone DependencyFactory bound to the telemetry connection explicitly.
 *
 * The result is real, versioned Doctrine migrations with their own
 * doctrine_migration_versions table living IN the telemetry database — a schema
 * history completely separate from the app's, exactly as required — reached with a
 * single line that the deploy hook runs after creating the database.
 *
 * Idempotent: a second run with nothing pending is a success that changes nothing.
 */
#[AsCommand(
    name: 'telemetry:migrate',
    description: 'Create/upgrade the telemetry database schema on the telemetry connection.',
)]
final class TelemetryMigrateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath,
        private readonly string $migrationsNamespace = 'Uhifadhi\\Telemetry\\Migrations',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL that would run, without executing it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $factory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => [$this->migrationsNamespace => $this->migrationsPath],
                'all_or_nothing' => true,
                'transactional' => true,
                'check_database_platform' => false,
            ]),
            new ExistingConnection($this->connection),
        );

        $factory->getMetadataStorage()->ensureInitialized();

        $target = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($target);

        if (0 === \count($plan)) {
            $io->success('Telemetry schema is already up to date.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true)->setDryRun($dryRun));

        $io->success(\sprintf(
            '%s %d telemetry migration(s) to %s.',
            $dryRun ? '[dry-run] would apply' : 'Applied',
            \count($plan),
            (string) $target,
        ));

        return Command::SUCCESS;
    }
}
