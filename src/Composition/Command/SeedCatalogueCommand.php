<?php

declare(strict_types=1);

namespace App\Composition\Command;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Module;
use App\Composition\Entity\Visualization;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Enum\VizType;
use App\Composition\Repository\AreaModuleRepository;
use App\Composition\Repository\ModuleRepository;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the module catalogue (the 15 modules + the Overview hub, from the designs) and backfills
 * every existing area with its modules — active in catalogue order, except Roads and Fires which
 * start parked in the "add a module" shop. Idempotent and non-destructive: safe to re-run and safe
 * against the dev/prod databases' real data.
 */
#[AsCommand(
    name: 'app:composition:seed',
    description: 'Seed the module catalogue and backfill areas with their modules (idempotent).',
)]
final class SeedCatalogueCommand extends Command
{
    /**
     * The six default charts on a Forest-loss module (from the design's viz grid).
     *
     * @var list<array{title: string, type: VizType}>
     */
    private const FOREST_VIZ = [
        ['title' => 'Annual loss', 'type' => VizType::Bar],
        ['title' => 'Cumulative loss', 'type' => VizType::Area],
        ['title' => 'Loss decomposition', 'type' => VizType::Waterfall],
        ['title' => 'Loss trend (LOESS)', 'type' => VizType::Lowess],
        ['title' => 'Dataset coverage', 'type' => VizType::Gantt],
        ['title' => 'Shelf growth', 'type' => VizType::Step],
    ];

    /**
     * The catalogue, in display order.
     *
     * @var list<array{slug: string, name: string, category: ModuleCategory, status: ModuleStatus, source: string, pinned: bool, active: bool}>
     */
    private const CATALOGUE = [
        ['slug' => 'overview', 'name' => 'Overview', 'category' => ModuleCategory::Hub, 'status' => ModuleStatus::Hub, 'source' => 'the park hub', 'pinned' => true, 'active' => true],
        ['slug' => 'forest', 'name' => 'Forest loss', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Live, 'source' => 'Hansen GFC', 'pinned' => false, 'active' => true],
        ['slug' => 'structure', 'name' => 'Forest structure', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'GEDI · CCI biomass', 'pinned' => false, 'active' => true],
        ['slug' => 'vegetation', 'name' => 'Vegetation', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'Sentinel-2 · EnMAP', 'pinned' => false, 'active' => true],
        ['slug' => 'landcover', 'name' => 'Land cover', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'ESA WorldCover', 'pinned' => false, 'active' => true],
        ['slug' => 'climate', 'name' => 'Climate', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'CHIRPS · WorldClim', 'pinned' => false, 'active' => true],
        ['slug' => 'drought', 'name' => 'Drought', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'SPEI · soil moisture', 'pinned' => false, 'active' => true],
        ['slug' => 'water', 'name' => 'Water', 'category' => ModuleCategory::Flux, 'status' => ModuleStatus::Template, 'source' => 'JRC surface water', 'pinned' => false, 'active' => true],
        ['slug' => 'settlement', 'name' => 'Settlement', 'category' => ModuleCategory::Pressure, 'status' => ModuleStatus::Template, 'source' => 'GHSL GHS-BUILT-S', 'pinned' => false, 'active' => true],
        ['slug' => 'livestock', 'name' => 'Livestock', 'category' => ModuleCategory::Pressure, 'status' => ModuleStatus::Template, 'source' => 'FAO GLW · census', 'pinned' => false, 'active' => true],
        ['slug' => 'tourism', 'name' => 'Tourism', 'category' => ModuleCategory::Pressure, 'status' => ModuleStatus::Template, 'source' => 'OSM · imagery', 'pinned' => false, 'active' => true],
        ['slug' => 'roads', 'name' => 'Roads', 'category' => ModuleCategory::Pressure, 'status' => ModuleStatus::Template, 'source' => 'OSM · GRIP', 'pinned' => false, 'active' => false],
        ['slug' => 'fires', 'name' => 'Fires', 'category' => ModuleCategory::Pressure, 'status' => ModuleStatus::Template, 'source' => 'FIRMS / VIIRS', 'pinned' => false, 'active' => false],
        ['slug' => 'wildlife', 'name' => 'Wildlife', 'category' => ModuleCategory::Biodiversity, 'status' => ModuleStatus::Template, 'source' => 'GBIF + covariates', 'pinned' => false, 'active' => true],
        ['slug' => 'stations', 'name' => 'Stations', 'category' => ModuleCategory::Biodiversity, 'status' => ModuleStatus::Template, 'source' => 'station feeds', 'pinned' => false, 'active' => true],
        ['slug' => 'statistics', 'name' => 'Statistics', 'category' => ModuleCategory::Biodiversity, 'status' => ModuleStatus::Template, 'source' => 'derived', 'pinned' => false, 'active' => true],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModuleRepository $modules,
        private readonly AreaModuleRepository $areaModules,
        private readonly AreaOfInterestRepository $areas,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1) Upsert the catalogue by slug.
        $bySlug = [];
        foreach (self::CATALOGUE as $position => $row) {
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

        // 3) Seed the six default Forest-loss charts on any forest module that has none.
        $vizSeeded = 0;
        foreach ($this->areas->findBy([], ['id' => 'ASC']) as $area) {
            foreach ($this->areaModules->forArea($area) as $areaModule) {
                if ('forest' !== $areaModule->getModule()?->getSlug() || !$areaModule->getVisualizations()->isEmpty()) {
                    continue;
                }
                foreach (self::FOREST_VIZ as $position => $viz) {
                    $this->em->persist((new Visualization())
                        ->setAreaModule($areaModule)
                        ->setTitle($viz['title'])
                        ->setType($viz['type'])
                        ->setXAxis('Year')
                        ->setYAxis('Loss (ha)')
                        ->setAggregation('Sum')
                        ->setPosition($position));
                    ++$vizSeeded;
                }
            }
        }
        $this->em->flush();

        $io->success(\sprintf(
            'Catalogue seeded (%d modules); %d area-module assignment(s) backfilled; %d visualization(s) seeded.',
            \count(self::CATALOGUE), $backfilled, $vizSeeded,
        ));

        return Command::SUCCESS;
    }
}
