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

use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\User;
use Uhifadhi\Seam\Entity\Module;
use Uhifadhi\Seam\Enum\ModuleCategory;
use Uhifadhi\Seam\Service\ModuleEntryRouteResolver;
use Uhifadhi\Shell\Model\ModuleCard;
use Uhifadhi\Shell\Model\ModuleGroup;

/**
 * Builds the area's Modules-tab card grid: one content-ful card per active module, grouped by the
 * flux / pressure / biodiversity taxonomy. A card carries only what the host can know — catalogue
 * identity only. Everything richer lives on the module's own pages (its bundle). No module is named here.
 *
 * The viewer's department is the lens over that grid: the modules their department works in lead,
 * as their own group, and every other module follows in the zone order the area already had. A
 * lens, never a fence — the set of cards is the same for everyone.
 */
final readonly class ModuleGridService
{
    /** The leading group's heading — the viewer's own department's modules. */
    private const LEAD_LABEL = 'Your department leads with';

    /**
     * The zones, in reading order — operations (the team's own work) first, then flux (the
     * ecosystem), pressure (people), then biodiversity/synthesis.
     *
     * OPERATIONS LEADS because this is what an area manager opens the grid for: the modules their
     * own people work in every day. The three readings of the area follow, in the order they always
     * had.
     */
    private const ZONES = [
        ModuleCategory::Operations->value => 'Operations — what the team is doing',
        ModuleCategory::Flux->value => 'Flux — what the ecosystem is doing',
        ModuleCategory::Pressure->value => 'Pressure — what people are doing',
        ModuleCategory::Biodiversity->value => 'Biodiversity & synthesis',
    ];

    public function __construct(
        private AreaCompositionService $composition,
        private ModuleEntryRouteResolver $entryRoutes,
        private DepartmentService $departments,
        private RouterInterface $router,
    ) {
    }

    /**
     * The area's active modules as cards: the viewer's department's own first (as one group named
     * by `department`), then the rest grouped in zone order. Empty groups are dropped, and a viewer
     * with no department gets the zone groups alone — exactly the grid there was before the lens.
     *
     * THE GROUPING IS THIS APPLICATION'S AND THE PICTURE IS NOT. Which cards, in
     * which groups, in which order, and which department leads one, is a reading
     * of the catalogue for a particular viewer on a particular area — it needs
     * the area, the viewer and the department lens, and it is decided here. How
     * a card looks belongs to uhifadhi/shell-module, which draws what this
     * returns and reads nothing off it.
     *
     * The URL is resolved here too, for the same reason: /areas/{uuid}/modules
     * is this application's URL space, and a layout that generated it would have
     * had to know what an area is.
     *
     * @return list<ModuleGroup>
     */
    public function grouped(AreaOfInterest $area, ?User $viewer = null): array
    {
        $modules = [];
        foreach ($this->composition->activeFor($area) as $areaModule) {
            $module = $areaModule->getModule();
            if (null === $module || $module->isPinned()) {
                continue; // the Overview hub is the area's Overview tab, not a module card
            }
            $modules[] = $module;
        }

        // The one lens the whole app orders by; the split below only reads what it moved to the front.
        $department = $viewer?->getPosition()?->getDepartment();
        $lead = [];
        $byZone = [];
        foreach ($this->departments->moduleOrderFor($viewer, $modules) as $module) {
            if ($department instanceof Department && $department->hasModule($module)) {
                $lead[] = $this->card($area, $module);

                continue;
            }
            $byZone[$module->getCategory()->value][] = $this->card($area, $module);
        }

        $groups = [];
        if ([] !== $lead) {
            $groups[] = new ModuleGroup(
                label: self::LEAD_LABEL,
                cards: $lead,
                department: (string) $department?->getName(),
            );
        }
        foreach (self::ZONES as $categoryValue => $label) {
            if ([] !== ($byZone[$categoryValue] ?? [])) {
                $groups[] = new ModuleGroup(label: $label, cards: $byZone[$categoryValue]);
            }
        }

        return $groups;
    }

    /**
     * A CARD WITH NOWHERE TO GO IS NOT A LINK. A catalogue row whose bundle
     * declares no entry route has no pages yet, so its tile carries no url and
     * the shell draws it inert — which this application learned by shipping
     * tiles that 404'd.
     */
    private function card(AreaOfInterest $area, Module $module): ModuleCard
    {
        $slug = (string) $module->getSlug();
        $entryRoute = $this->entryRoutes->entryRouteFor($slug);

        return new ModuleCard(
            slug: $slug,
            title: (string) $module->getName(),
            status: $module->getStatus()->value,
            source: (string) $module->getDataSource(),
            url: null === $entryRoute ? null : $this->router->generate($entryRoute, ['uuid' => $area->getUuidString()]),
        );
    }
}
