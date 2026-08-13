<?php

declare(strict_types=1);

namespace App\Composition\Service;

use App\Composition\Entity\AreaModule;
use App\Composition\Entity\Module;
use App\Composition\Enum\ModuleCategory;
use App\Composition\Repository\AreaModuleRepository;
use App\Composition\Repository\ModuleRepository;
use App\Spatial\Entity\AreaOfInterest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The read + write model behind the "customize modules" screen: an area's active sub-nav, its
 * parked-module shop, and the mutations that move modules between the two, reorder them, and add a
 * catalogue module onto the area. All ordering is by {@see AreaModule::getPosition()}; the pinned
 * Overview module always leads and is never reordered or switched off.
 */
final readonly class AreaCompositionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AreaModuleRepository $areaModules,
        private ModuleRepository $modules,
    ) {
    }

    /**
     * The area's active modules in sub-nav order — what the sub-nav renders.
     *
     * @return list<AreaModule>
     */
    public function activeFor(AreaOfInterest $area): array
    {
        return $this->areaModules->activeForArea($area);
    }

    /**
     * The area's parked (switched-off) modules, in position order — the "inactive" shop column.
     *
     * @return list<AreaModule>
     */
    public function parkedFor(AreaOfInterest $area): array
    {
        return array_values(array_filter(
            $this->areaModules->forArea($area),
            static fn (AreaModule $am): bool => !$am->isActive(),
        ));
    }

    /**
     * The parked modules grouped by category (as the shop lists them), in category then position order.
     *
     * @return array<string, list<AreaModule>>
     */
    public function parkedByCategory(AreaOfInterest $area): array
    {
        $grouped = [];
        foreach ($this->parkedFor($area) as $areaModule) {
            $category = $areaModule->getModule()?->getCategory() ?? ModuleCategory::Flux;
            $grouped[$category->label()][] = $areaModule;
        }

        return $grouped;
    }

    /**
     * Switch a module on or off for its area. The pinned Overview can never be switched off.
     */
    public function setActive(AreaModule $areaModule, bool $active): void
    {
        if ($areaModule->getModule()?->isPinned()) {
            return;
        }

        $areaModule->setActive($active);
        $this->em->flush();
    }

    /**
     * Reorder the area's active modules to match the given AreaModule uuids. Unlisted modules keep
     * their relative order after the listed ones; the pinned Overview is forced to the front.
     *
     * @param list<string> $orderedUuids
     */
    public function reorder(AreaOfInterest $area, array $orderedUuids): void
    {
        $rank = array_flip($orderedUuids);
        $position = 1; // Overview (pinned) holds 0.

        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            if ($areaModule->getModule()?->isPinned()) {
                $areaModule->setPosition(0);

                continue;
            }
            $uuid = $areaModule->getUuidString();
            // Listed modules take their new slot; unlisted ones fall in after, order preserved.
            $areaModule->setPosition(isset($rank[$uuid]) ? $rank[$uuid] + 1 : ($position + 1_000));
            ++$position;
        }

        $this->em->flush();
    }

    /**
     * Add a catalogue module onto an area (or re-activate a parked one). Idempotent: an already
     * active assignment is left as-is. New assignments land after the current modules.
     */
    public function addToArea(AreaOfInterest $area, Module $module): AreaModule
    {
        foreach ($this->areaModules->forArea($area) as $existing) {
            if ($existing->getModule()?->getId() === $module->getId()) {
                $existing->setActive(true);
                $this->em->flush();

                return $existing;
            }
        }

        $assignment = (new AreaModule())
            ->setArea($area)
            ->setModule($module)
            ->setActive(true)
            ->setPosition($this->nextPosition($area));

        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /**
     * The catalogue with each module's on/off state for this area — drives the "add a module" modal.
     *
     * @return list<array{module: Module, active: bool}>
     */
    public function catalogueFor(AreaOfInterest $area): array
    {
        $stateBySlug = [];
        foreach ($this->areaModules->forArea($area) as $areaModule) {
            $stateBySlug[(string) $areaModule->getModule()?->getSlug()] = $areaModule->isActive();
        }

        return array_map(
            static fn (Module $module): array => [
                'module' => $module,
                'active' => $stateBySlug[(string) $module->getSlug()] ?? false,
            ],
            $this->modules->catalogue(),
        );
    }

    private function nextPosition(AreaOfInterest $area): int
    {
        $max = 0;
        foreach ($this->areaModules->forArea($area) as $areaModule) {
            $max = max($max, $areaModule->getPosition());
        }

        return $max + 1;
    }
}
