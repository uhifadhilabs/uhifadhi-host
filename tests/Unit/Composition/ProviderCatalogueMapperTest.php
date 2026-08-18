<?php

declare(strict_types=1);

namespace App\Tests\Unit\Composition;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use App\Composition\Service\ProviderCatalogueMapper;
use PHPUnit\Framework\TestCase;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;
use UhifadhiLabs\ModuleContracts\ModuleProviderTrait;

/**
 * A bundle-provided module joins the catalogue through the same shape as a
 * built-in row: its category/status strings are coerced to the app's enums
 * (unknown values fall back), and it starts PARKED so an admin opts it in per
 * area. No container — pure mapping.
 */
final class ProviderCatalogueMapperTest extends TestCase
{
    private function provider(string $category = 'pressure', string $status = 'live'): ModuleProviderInterface
    {
        return new class($category, $status) implements ModuleProviderInterface {
            use ModuleProviderTrait;

            public function __construct(private string $cat, private string $stat)
            {
            }

            public function slug(): string
            {
                return 'uhakiki';
            }

            public function name(): string
            {
                return 'Uhakiki';
            }

            public function category(): string
            {
                return $this->cat;
            }

            public function status(): string
            {
                return $this->stat;
            }

            public function dataSource(): ?string
            {
                return 'verification';
            }
        };
    }

    public function testMapsAProviderToACatalogueRowAtTheGivenPosition(): void
    {
        $row = new ProviderCatalogueMapper()->toRow($this->provider(), 20);

        self::assertSame('uhakiki', $row['slug']);
        self::assertSame('Uhakiki', $row['name']);
        self::assertSame(ModuleCategory::Pressure, $row['category']);
        self::assertSame(ModuleStatus::Live, $row['status']);
        self::assertSame('verification', $row['source']);
        self::assertFalse($row['pinned']);
        self::assertSame(20, $row['position']);
        self::assertFalse($row['active'], 'A bundle module starts parked — opted in per area.');
    }

    public function testUnknownCategoryAndStatusFallBack(): void
    {
        $row = new ProviderCatalogueMapper()->toRow($this->provider(category: 'operations', status: 'weird'), 0);

        self::assertSame(ModuleCategory::Pressure, $row['category'], 'Unknown category falls back to Pressure.');
        self::assertSame(ModuleStatus::Live, $row['status'], 'Unknown status falls back to Live.');
    }
}
