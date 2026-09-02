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

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Repository\ModuleRepository;

/**
 * WHAT THIS AREA HAS AND WHAT IT DOES NOT — read once, in one place.
 *
 * Two widgets on the area overview are about the seam itself: AO·08 ("Modules in
 * this area") lists what is installed AND, muted underneath it, the catalogue
 * modules that are not; NX·01 ("Not installed here") is the same absence given a
 * whole card. They used to walk the catalogue separately, which is exactly how a
 * card comes to say "8 in the catalogue" over two rows: two readings of one
 * table, made a query apart, disagreeing about what the catalogue is.
 *
 * THE CATALOGUE IS THE ADD-A-MODULE SHOP'S CATALOGUE — {@see ModuleRepository::catalogue()},
 * the same ordered list the Modules tab offers — so a module that can be added
 * there is a module the overview names here, in the same order, without either
 * having to know about the other.
 *
 * NOTHING HERE INVENTS DATA. A module the area does not have has contributed
 * nothing, and the only things said about it are its own catalogue name and its
 * own words for what it is about. What it WOULD contribute to this page is a
 * promise only an installed module's provider can make, and it has not been
 * asked.
 */
final readonly class AreaModuleLedger
{
    public function __construct(
        private ModuleRepository $modules,
        private AreaModuleRepository $areaModules,
    ) {
    }

    /**
     * ONE READING, both halves. The catalogue is walked once and split, so the
     * count and the rows can never describe two different tables.
     *
     * @return array{
     *     installed: list<array{slug: string, name: string, since: ?\DateTimeImmutable}>,
     *     absent: list<array{slug: string, name: string, source: ?string}>,
     *     catalogueCount: int,
     *     installedCount: int,
     * }
     */
    public function for(AreaOfInterest $area): array
    {
        $installed = [];
        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            $module = $areaModule->getModule();
            $slug = $module?->getSlug();
            if (null === $module || null === $slug) {
                continue;
            }
            // The area's OWN order, which is the order the library heads its
            // sections in — not the catalogue's.
            $installed[$slug] = [
                'slug' => $slug,
                'name' => $module->getName() ?? $slug,
                'since' => $areaModule->getCreatedAt(),
            ];
        }

        $catalogue = $this->modules->catalogue();

        $absent = [];
        foreach ($catalogue as $module) {
            $slug = $module->getSlug();
            if (null === $slug || isset($installed[$slug])) {
                continue;
            }
            $absent[] = [
                'slug' => $slug,
                'name' => $module->getName() ?? $slug,
                'source' => $module->getDataSource(),
            ];
        }

        return [
            'installed' => array_values($installed),
            'absent' => $absent,
            'catalogueCount' => \count($catalogue),
            'installedCount' => \count($installed),
        ];
    }
}
