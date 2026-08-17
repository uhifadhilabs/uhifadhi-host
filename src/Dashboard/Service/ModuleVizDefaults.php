<?php

declare(strict_types=1);

namespace App\Dashboard\Service;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Visualization;
use App\Composition\Enum\VizType;
use App\Dashboard\Module\ModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Materialises a module's default visualizations — declared by its {@see \App\Spatial\Module\ModuleDefinition}
 * — as editable Visualization rows, ONCE per area-module (guarded by {@see AreaModule::isVizSeeded()})
 * so a user who deletes them isn't fighting a resurrection. No module is named here: the definition
 * registry supplies whatever the module ships.
 */
final readonly class ModuleVizDefaults
{
    public function __construct(
        private EntityManagerInterface $em,
        private ModuleRegistry $registry,
    ) {
    }

    public function ensure(AreaModule $areaModule): void
    {
        if ($areaModule->isVizSeeded()) {
            return;
        }
        $slug = $areaModule->getModule()?->getSlug();
        if (null === $slug) {
            return;
        }

        $areaModule->setVizSeeded(true);
        foreach ($this->registry->definitionFor($slug)->defaultVisualizations() as $position => $spec) {
            $viz = (new Visualization())
                    ->setTitle($spec->title)
                    ->setType(VizType::tryFrom($spec->type) ?? VizType::Bar)
                    ->setDatasetKey($spec->datasetKey)
                    ->setXAxis($spec->x)
                    ->setYAxis($spec->y)
                    ->setColourBy(null)
                    ->setAggregation('None')
                    ->setPosition($position);
            $areaModule->addVisualization($viz); // both sides in sync — this request sees it too
            $this->em->persist($viz);
        }
        $this->em->flush();
    }
}
