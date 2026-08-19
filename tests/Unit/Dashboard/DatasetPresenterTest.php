<?php

declare(strict_types=1);

namespace Uhifadhi\Tests\Unit\Dashboard;

use Uhifadhi\Dashboard\Service\DatasetPresenter;
use Uhifadhi\Ingestion\Entity\Dataset;
use Uhifadhi\Ingestion\Enum\DatasetKind;
use PHPUnit\Framework\TestCase;

/**
 * The presenter infers column types from untyped stored rows and computes describe() over the numeric
 * columns — the two transforms behind the Dataframe and Explore tabs.
 */
final class DatasetPresenterTest extends TestCase
{
    private function table(): Dataset
    {
        return (new Dataset())
            ->setModuleSlug('landcover')
            ->setKey('landcover_class')
            ->setKind(DatasetKind::Table)
            ->setColumns(['class', 'area_km2', 'n_patches'])
            ->setRows([
                ['Grassland', 2589.78, 142],
                ['Shrubland', 521.38, 402],
                ['Tree cover', 199.15, 210],
            ])
            ->setSource('ESA WorldCover');
    }

    public function testItInfersChrIntAndDblColumnTypes(): void
    {
        $presenter = new DatasetPresenter();

        self::assertSame(['chr', 'dbl', 'int'], $presenter->types($this->table()));
        self::assertSame(['area_km2', 'n_patches'], $presenter->numericColumns($this->table()));
    }

    public function testItDescribesOnlyTheNumericColumns(): void
    {
        $describe = (new DatasetPresenter())->describe($this->table());

        self::assertSame(['area_km2', 'n_patches'], array_column($describe, 'column'));
        $area = $describe[0];
        self::assertSame(3, $area['count']);
        self::assertEqualsWithDelta(1103.437, $area['mean'], 0.01);
        self::assertSame(199.15, $area['min']);
        self::assertSame(521.38, $area['median']);
        self::assertSame(2589.78, $area['max']);
    }

    public function testAnEmptyDatasetYieldsNoTypesOrDescribe(): void
    {
        $empty = (new Dataset())->setColumns([])->setRows([]);
        $presenter = new DatasetPresenter();

        self::assertSame([], $presenter->types($empty));
        self::assertSame([], $presenter->describe($empty));
    }
}
