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

namespace Uhifadhi\Overview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Model\Widget;
use Uhifadhi\Model\WidgetGroup;
use Uhifadhi\Repository\AreaModuleRepository;
use Uhifadhi\Repository\ModuleRepository;
use Uhifadhi\Service\AreaOverviewCatalogue;

/**
 * THE SEAM, DRAWN HONESTLY — the not-installed-here section, last in the
 * library.
 *
 * NOTHING HERE INVENTS DATA, because there is none: a module that is not
 * installed in this area has contributed nothing, and a card that drew permits
 * figures would be a fake module rather than an honest affordance. What it names
 * is what the catalogue holds and where installing one would put it, which is
 * the whole promise the composition makes.
 *
 * It is a WIDGET, not chrome: a person who does not want the reminder switches
 * it off, and the "Module columns" design puts it at the foot of the page where
 * the missing column would be.
 */
#[AutoconfigureTag(OverviewContributorInterface::TAG)]
final readonly class NextModulesContributor implements OverviewContributorInterface
{
    public function __construct(
        private ModuleRepository $modules,
        private AreaModuleRepository $areaModules,
    ) {
    }

    public function moduleSlug(): string
    {
        return AreaOverviewCatalogue::SEAM_SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            AreaOverviewCatalogue::SEAM_SLUG,
            'Not installed in this area',
            'The seam, drawn honestly. Nothing here invents data: it names what the catalogue holds, what each of those modules would contribute to THIS page, and where installing one would put it. Install permits and a fifth headed section appears above this one — nothing on the page is redesigned.',
        );
    }

    public function widgets(): array
    {
        return [
            new Widget(
                'nextmod',
                'Not installed here',
                AreaOverviewCatalogue::SEAM_SLUG,
                12,
                [12, 9, 6],
                on: false,
                note: 'What the catalogue holds and what each of those modules would put on this page. Names nothing it does not have.',
            ),
        ];
    }

    public function partialPattern(): string
    {
        return 'area/overview/_w_%s.html.twig';
    }

    public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $installed = [];
        foreach ($this->areaModules->forArea($area) as $areaModule) {
            if ($areaModule->isActive() && null !== $areaModule->getModule()?->getSlug()) {
                $installed[] = $areaModule->getModule()->getSlug();
            }
        }

        $absent = [];
        foreach ($this->modules->findAll() as $module) {
            $slug = $module->getSlug();
            if (null === $slug || \in_array($slug, $installed, true)) {
                continue;
            }
            $absent[] = [
                'slug' => $slug,
                'name' => $module->getName() ?? $slug,
                // The module's own words for what it is about. NOT a promise
                // about what it would contribute here: only an installed
                // module's provider can say that, and it has not been asked.
                'source' => $module->getDataSource(),
            ];
        }

        return [
            'absent' => $absent,
            'catalogueCount' => \count($this->modules->findAll()),
            'installedCount' => \count($installed),
        ];
    }
}
