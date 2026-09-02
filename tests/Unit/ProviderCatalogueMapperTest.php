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

namespace Uhifadhi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use Uhifadhi\Service\ProviderCatalogueMapper;
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

            public function dataSource(): string
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
        $row = new ProviderCatalogueMapper()->toRow($this->provider(category: 'hydrology', status: 'weird'), 0);

        // OPERATIONS IS WHAT AN UNPLACED MODULE IS. This is an operations
        // platform: a provider naming a category the host does not have is far
        // likelier to be somebody's daily work than a reading of the ecosystem,
        // and filing it under "pressure" would say the opposite — that the
        // module measures what PEOPLE are doing TO the area.
        self::assertSame(ModuleCategory::Operations, $row['category'], 'Unknown category falls back to Operations.');
        self::assertSame(ModuleStatus::Live, $row['status'], 'Unknown status falls back to Live.');
    }

    /**
     * A MODULE MAY SAY IT IS OPERATIONAL, and until now it could not: the
     * catalogue's three categories were all readings of the AREA (what the
     * ecosystem is doing, what people are doing to it, what lives in it), and
     * the rangers' own work — patrols, incidents, rosters — had nowhere to go
     * but "pressure", which says the opposite of what it is.
     */
    public function testAProviderMayFileItselfUnderOperations(): void
    {
        $row = new ProviderCatalogueMapper()->toRow($this->provider(category: 'operations'), 0);

        self::assertSame(ModuleCategory::Operations, $row['category']);
        self::assertSame('Operations', $row['category']->label());
    }
}
