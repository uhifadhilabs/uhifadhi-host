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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Uhifadhi\Entity\AreaModule;
use Uhifadhi\Entity\Module;
use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Repository\ModuleRepository;
use Uhifadhi\Service\ProviderCatalogueMapper;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

/**
 * Seeds the module catalogue and backfills every existing area with its modules.
 *
 * PROVIDER-DRIVEN: apart from the host's own Overview hub, every catalogue row
 * comes from a tagged {@see ModuleProviderInterface} — a module bundle declares
 * itself and appears here; uninstalling it stops seeding it. The host never
 * hardcodes a module list (the retired in-app catalogue lives on the
 * legacy-modules branch). Idempotent and non-destructive: safe to re-run and
 * safe against the dev/prod databases' real data.
 */
#[AsCommand(
    name: 'app:seed:catalogue',
    aliases: ['app:composition:seed'],
    description: 'Seed the module catalogue from the installed module providers (idempotent).',
)]
final class SeedCatalogueCommand extends Command
{
    /**
     * @param iterable<ModuleProviderInterface> $moduleProviders every tagged provider — installed module bundles
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModuleRepository $modules,
        private readonly AreaModuleRepository $areaModules,
        private readonly AreaOfInterestRepository $areas,
        private readonly ProviderCatalogueMapper $providerMapper,
        #[AutowireIterator('uhifadhi.module')]
        private readonly iterable $moduleProviders = [],
    ) {
        parent::__construct();
    }

    /**
     * One row per installed provider — the Overview is the area's own view,
     * never a catalogue row.
     *
     * @return list<array{slug: string, name: string, category: ModuleCategory, status: ModuleStatus, source: string, pinned: bool, active: bool}>
     */
    private function catalogue(): array
    {
        $rows = [];

        $position = 0;
        foreach ($this->moduleProviders as $provider) {
            $row = $this->providerMapper->toRow($provider, $position++);
            unset($row['position']); // positional index below drives Module::position
            $rows[] = $row;
        }

        return $rows;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1) Upsert the catalogue by slug (hub + every installed module provider).
        $catalogue = $this->catalogue();
        $bySlug = [];
        foreach ($catalogue as $position => $row) {
            $module = $this->modules->findBySlug($row['slug']) ?? new Module();
            $module->setSlug($row['slug'])
                ->setName($row['name'])
                ->setCategory($row['category'])
                ->setStatus($row['status'])
                ->setDataSource($row['source'])
                ->setPinned($row['pinned'])
                ->setPosition($position);
            $this->em->persist($module);
            $bySlug[$row['slug']] = [$module, $row['active']];
        }
        $this->em->flush();

        // 2) Backfill every area with any modules it is missing (create-only — never
        //    touches an area's existing on/off or ordering).
        $backfilled = 0;
        foreach ($this->areas->findBy([], ['id' => 'ASC']) as $area) {
            $have = [];
            foreach ($this->areaModules->forArea($area) as $areaModule) {
                $have[(string) $areaModule->getModule()?->getSlug()] = true;
            }
            foreach ($bySlug as $slug => [$module, $active]) {
                if (isset($have[$slug])) {
                    continue;
                }
                $this->em->persist((new AreaModule())
                    ->setArea($area)
                    ->setModule($module)
                    ->setActive($active)
                    ->setPosition($module->getPosition()));
                ++$backfilled;
            }
        }
        $this->em->flush();

        $io->success(\sprintf(
            'Catalogue seeded (%d modules from %d provider(s)); %d area-module assignment(s) backfilled.',
            \count($catalogue),
            \count($catalogue),
            $backfilled,
        ));

        return Command::SUCCESS;
    }
}
