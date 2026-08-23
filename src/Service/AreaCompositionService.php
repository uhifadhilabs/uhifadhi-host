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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Entity\AreaModule;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Entity\Module;
use Uhifadhi\Model\ParkedModule;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Repository\ModuleRepository;

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
     * The area's parked modules — the "inactive" shop column: its switched-off assignments (in
     * position order) followed by every catalogue module the area has no assignment for at all (in
     * catalogue order). Availability is derived from the catalogue, not from rows: an area created
     * after the catalogue seed ran owns no rows, and must still see the whole shop. Nothing is
     * persisted here — a row appears only when a module is added.
     *
     * @return list<ParkedModule>
     */
    public function parkedFor(AreaOfInterest $area): array
    {
        $assignments = [];
        foreach ($this->areaModules->forArea($area) as $areaModule) {
            $assignments[(string) $areaModule->getModule()?->getSlug()] = $areaModule;
        }

        $parked = [];
        foreach ($assignments as $areaModule) {
            $module = $areaModule->getModule();
            if (!$areaModule->isActive() && null !== $module) {
                $parked[] = new ParkedModule($module, $areaModule);
            }
        }

        foreach ($this->modules->catalogue() as $module) {
            if (!isset($assignments[(string) $module->getSlug()])) {
                $parked[] = new ParkedModule($module);
            }
        }

        return $parked;
    }

    /**
     * The parked modules grouped by category (as the shop lists them), in category then position order.
     *
     * @return array<string, list<ParkedModule>>
     */
    public function parkedByCategory(AreaOfInterest $area): array
    {
        $grouped = [];
        foreach ($this->parkedFor($area) as $parked) {
            $grouped[$parked->module->getCategory()->label()][] = $parked;
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
