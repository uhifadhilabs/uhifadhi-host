<?php

declare(strict_types=1);

namespace App\Composition\Factory;

use App\Composition\Entity\Visualization;
use App\Composition\Enum\VizType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Visualization>
 */
final class VisualizationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Visualization::class;
    }

    protected function defaults(): array
    {
        return [
            'areaModule' => AreaModuleFactory::new(),
            'title' => self::faker()->unique()->words(2, true),
            'type' => VizType::Bar,
            'datasetKey' => null,
            'xAxis' => 'Year',
            'yAxis' => 'Loss (ha)',
            'colourBy' => null,
            'aggregation' => 'None',
            'position' => self::faker()->numberBetween(0, 20),
        ];
    }
}
