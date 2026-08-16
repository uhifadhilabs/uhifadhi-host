<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Dashboard\Module\GenericModule;
use App\Dashboard\Module\ModuleRegistry;
use App\Forest\Module\ForestModule;
use App\LandCover\Module\LandCoverModule;
use App\Roads\Module\RoadsModule;
use App\Settlement\Module\SettlementModule;
use App\Vegetation\Module\VegetationModule;
use App\Wildlife\Module\WildlifeModule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Open/Closed backbone: tagged ModuleDefinitions are collected from their bounded contexts and
 * resolved by slug; a slug with no shipped class falls back to GenericModule (all defaults) — so
 * adding a module never edits generic code, and a data-only module needs no PHP at all.
 */
final class ModuleRegistryTest extends KernelTestCase
{
    public function testTaggedDefinitionsResolveBySlugAndUnknownSlugsGetTheGenericFallback(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ModuleRegistry::class);
        \assert($registry instanceof ModuleRegistry);

        self::assertInstanceOf(ForestModule::class, $registry->definitionFor('forest'));
        self::assertInstanceOf(LandCoverModule::class, $registry->definitionFor('landcover'));
        self::assertInstanceOf(VegetationModule::class, $registry->definitionFor('vegetation'));
        self::assertInstanceOf(SettlementModule::class, $registry->definitionFor('settlement'));
        self::assertInstanceOf(RoadsModule::class, $registry->definitionFor('roads'));
        self::assertInstanceOf(WildlifeModule::class, $registry->definitionFor('wildlife'));

        $generic = $registry->definitionFor('fires');
        self::assertInstanceOf(GenericModule::class, $generic);
        self::assertSame('fires', $generic->slug());
        self::assertSame([], $generic->defaultVisualizations());
        self::assertNull($generic->methodCaption());
        self::assertSame('fires_map', $generic->mapDatasetKey());
    }

    public function testDefinitionsCarryTheirModulesDefaults(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ModuleRegistry::class);
        \assert($registry instanceof ModuleRegistry);

        $landcover = $registry->definitionFor('landcover');
        self::assertSame(['Class areas', 'Fragmentation'], array_map(static fn ($v) => $v->title, $landcover->defaultVisualizations()));
        self::assertSame('#f5e07a', $landcover->palette()['Grassland']);
        self::assertNotNull($landcover->methodCaption());

        $settlement = $registry->definitionFor('settlement');
        self::assertSame(['builtup_epoch'], array_values(array_unique(
            array_map(static fn ($v) => $v->datasetKey, $settlement->defaultVisualizations()),
        )));
        self::assertSame('settlement_map', $settlement->mapDatasetKey());
        self::assertSame('#c81e1e', $settlement->palette()['New settlement by 2020']);

        $vegetation = $registry->definitionFor('vegetation');
        self::assertSame(['phenology_16day'], array_values(array_unique(
            array_map(static fn ($v) => $v->datasetKey, $vegetation->defaultVisualizations()),
        )));
        self::assertNotNull($vegetation->methodCaption());

        $forest = $registry->definitionFor('forest');
        self::assertSame(['forest_loss_year'], array_values(array_unique(
            array_map(static fn ($v) => $v->datasetKey, $forest->defaultVisualizations()),
        )));
    }
}
