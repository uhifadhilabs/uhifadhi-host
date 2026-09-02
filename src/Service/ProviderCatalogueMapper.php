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

namespace Uhifadhi\Service;

use Uhifadhi\Enum\ModuleCategory;
use Uhifadhi\Enum\ModuleStatus;
use UhifadhiLabs\ModuleContracts\ModuleProviderInterface;

/**
 * Turns a module provider (a built-in module's or an installed bundle's) into a
 * catalogue row of the same shape the seed uses for its hardcoded modules. Its
 * category/status strings are coerced to the app's enums (unknown → a safe
 * default, so a bundle can never break the seed with a typo).
 *
 * INSTALLABLE OR CORE. An installable module starts PARKED, so an admin opts it
 * in per area — right for a capability an area may not want. A CORE module
 * starts active, because it is machinery other surfaces already depend on and
 * there is no real choice to offer: the map platform is the first, and an area
 * with it switched off would not have fewer features, it would have a blank
 * overview map.
 *
 * The flag decides the INITIAL state and nothing more. The Customize page still
 * governs an area's modules afterwards, and this seed is create-only — it never
 * revisits a row an admin has since changed. That is the honest limit of the
 * seam as it stands.
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
            'category' => ModuleCategory::tryFrom($provider->category()) ?? ModuleCategory::Operations,
            'status' => ModuleStatus::tryFrom($provider->status()) ?? ModuleStatus::Live,
            'source' => $provider->dataSource() ?? '',
            'pinned' => $provider->pinned(),
            // Parked unless the module is core — an installable module is enabled
            // per area from the Customize page.
            'active' => $provider->core(),
            'position' => $position,
        ];
    }
}
