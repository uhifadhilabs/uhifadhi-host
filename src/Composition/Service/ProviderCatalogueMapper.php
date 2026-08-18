<?php

declare(strict_types=1);

namespace App\Composition\Service;

use App\Composition\Enum\ModuleCategory;
use App\Composition\Enum\ModuleStatus;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

/**
 * Turns a module provider (a built-in module's or an installed bundle's) into a
 * catalogue row of the same shape the seed uses for its hardcoded modules. A
 * bundle module starts PARKED so an admin opts it in per area, and its
 * category/status strings are coerced to the app's enums (unknown → a safe
 * default, so a bundle can never break the seed with a typo).
 */
final class ProviderCatalogueMapper
{
    /**
     * @return array{slug: string, name: string, category: ModuleCategory, status: ModuleStatus, source: string, pinned: bool, active: bool, position: int}
     */
    public function toRow(ModuleProviderInterface $provider, int $position): array
    {
        return [
            'slug' => $provider->slug(),
            'name' => $provider->name(),
            'category' => ModuleCategory::tryFrom($provider->category()) ?? ModuleCategory::Pressure,
            'status' => ModuleStatus::tryFrom($provider->status()) ?? ModuleStatus::Live,
            'source' => $provider->dataSource() ?? '',
            'pinned' => $provider->pinned(),
            'active' => false, // parked by default — enabled per area from the Customize page
            'position' => $position,
        ];
    }
}
