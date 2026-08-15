<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Dashboard\Module\GenericModule;
use App\Dashboard\Module\ModuleRegistry;
use App\Forest\Module\ForestModule;
use App\LandCover\Module\LandCoverModule;
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

        $generic = $registry->definitionFor('roads');
        self::assertInstanceOf(GenericModule::class, $generic);
        self::assertSame('roads', $generic->slug());
        self::assertSame([], $generic->defaultVisualizations());
        self::assertNull($generic->methodCaption());
        self::assertSame('roads_map', $generic->mapDatasetKey());
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

        $forest = $registry->definitionFor('forest');
        self::assertSame(['forest_loss_year'], array_values(array_unique(
            array_map(static fn ($v) => $v->datasetKey, $forest->defaultVisualizations()),
        )));
    }
}
