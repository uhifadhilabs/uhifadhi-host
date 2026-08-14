<?php

declare(strict_types=1);

namespace App\Ingestion\Factory;

use App\Ingestion\Entity\Dataset;
use App\Ingestion\Enum\DatasetKind;
use App\Spatial\Factory\AreaOfInterestFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Dataset>
 */
final class DatasetFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Dataset::class;
    }

    protected function defaults(): array
    {
        return [
            'area' => AreaOfInterestFactory::new(),
            'moduleSlug' => 'landcover',
            'key' => self::faker()->unique()->slug(2),
            'kind' => DatasetKind::Series,
            'columns' => ['class', 'area_km2', 'pct'],
            'rows' => [['Grassland', 5123.4, 61.8], ['Tree cover', 1204.2, 14.5]],
            'source' => 'ESA WorldCover 2021 v200',
        ];
    }
}
