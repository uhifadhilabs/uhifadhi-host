<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Visualization;
use App\Composition\Enum\VizType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A module ships with a set of default visualizations bound to its dataset — the charts its Overview
 * shows out of the box and that appear (editable) in its Settings. They are seeded ONCE per area-module
 * (guarded by {@see AreaModule::isVizSeeded()}) so a user who deletes them isn't fighting a resurrection
 * on the next view. The Overview renders whatever visualizations are configured — never a hardcoded chart.
 */
final readonly class ModuleVizDefaults
{
    /** @var array<string, list<array{title: string, type: VizType, key: string, x: string, y: string}>> */
    private const DEFAULTS = [
        'landcover' => [
            ['title' => 'Class areas', 'type' => VizType::Bar, 'key' => 'landcover_class', 'x' => 'class', 'y' => 'area_km2'],
            ['title' => 'Fragmentation', 'type' => VizType::Bar, 'key' => 'landcover_class', 'x' => 'class', 'y' => 'patch_density'],
        ],
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Seed this module's default visualizations if it has never been seeded and defines any. Idempotent:
     * once seeded, never again — so deleting the defaults sticks.
     */
    public function ensure(AreaModule $areaModule): void
    {
        if ($areaModule->isVizSeeded()) {
            return;
        }
        $slug = $areaModule->getModule()?->getSlug();
        $defaults = self::DEFAULTS[$slug] ?? null;

        $areaModule->setVizSeeded(true);
        if (null !== $defaults) {
            foreach ($defaults as $position => $spec) {
                $this->em->persist(
                    (new Visualization())
                        ->setAreaModule($areaModule)
                        ->setTitle($spec['title'])
                        ->setType($spec['type'])
                        ->setDatasetKey($spec['key'])
                        ->setXAxis($spec['x'])
                        ->setYAxis($spec['y'])
                        ->setColourBy(null)
                        ->setAggregation('None')
                        ->setPosition($position),
                );
            }
        }
        $this->em->flush();
    }
}
