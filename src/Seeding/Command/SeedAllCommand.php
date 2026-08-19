<?php

declare(strict_types=1);

namespace Uhifadhi\Seeding\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the app's OWN demo baseline in one shot: accounts → area → catalogue
 * (needs the area to backfill its modules). Each installed module seeds its own
 * data with its own <module>:seed:all (e.g. uhakiki:seed:all) — the app never
 * references a module's commands, so it stays module-blind. Run those after this.
 */
#[AsCommand(
    name: 'app:seed:all',
    description: 'Seed the app baseline (accounts, area, catalogue). Modules seed themselves via <module>:seed:all.',
)]
final class SeedAllCommand extends Command
{
    /** @var list<string> */
    private const array STEPS = [
        'app:seed:accounts',
        'app:seed:area',
        'app:seed:catalogue',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->getApplication();
        if (null === $application) {
            return Command::FAILURE;
        }

        foreach (self::STEPS as $name) {
            $io->section($name);
            $code = $application->find($name)->run(new ArrayInput([]), $output);
            if (Command::SUCCESS !== $code) {
                $io->error(\sprintf('Step "%s" failed (exit %d) — stopping.', $name, $code));

                return $code;
            }
        }

        $io->success('Demo fully seeded.');

        return Command::SUCCESS;
    }
}
