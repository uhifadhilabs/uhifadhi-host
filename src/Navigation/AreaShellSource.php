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

namespace Uhifadhi\Navigation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Shell\Contract\AreaShellSourceInterface;
use Uhifadhi\Shell\Model\AreaTab;

/**
 * WHICH AREA THE VIEWER IS IN, AND WHICH OF ITS SCREENS THEY MAY REACH.
 *
 * THE ONE PLACE THE ANSWER IS DECIDED. The tab list used to live twice — once in
 * a Twig partial every area page included by hand, and once in the sidebar's
 * runtime so the location tree could draw the same rows. The two had to be
 * edited together, which is another way of saying that one day they would not
 * be. The shell renders both from this, so they cannot disagree.
 *
 * WHAT THIS DECIDES AND THE SHELL DOES NOT: which screens an area has, and which
 * of them this viewer may reach. Both are the application's model of an area and
 * of its team, and neither is a layout's business. A tab the viewer may not have
 * is simply absent from what this returns — never a greyed-out word, because a
 * disabled "Settings" tells a ranger that a settings screen exists and they are
 * not trusted with it, which is a worse product than not mentioning it.
 *
 * ROUTE-TOLERANT, for the same reason the sidebar is: a screen whose route has
 * not merged yet contributes no tab rather than breaking every page in the app.
 */
final class AreaShellSource implements AreaShellSourceInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly AreaOfInterestRepository $areas,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * The screens of the area this request is inside — the seam the shell reads.
     *
     * @return list<AreaTab>
     */
    public function tabs(): array
    {
        $area = $this->currentArea($this->requestStack->getCurrentRequest());

        return null === $area ? [] : $this->screensOf($area);
    }

    /**
     * The screens of ANY area, whether or not the viewer is in it — because the
     * sidebar lists every area and each one unfolds to its own screens, so you
     * can reach another area's zones without visiting the area first. Only the
     * area you are actually in has one of them lit.
     *
     * This is the second reading of the one list, and the reason there is only
     * one list: the strip above the page and the branch in the sidebar are drawn
     * from this same method, so they cannot disagree the way the two hand-kept
     * copies they replaced eventually would have.
     *
     * @return list<AreaTab>
     */
    public function screensOf(AreaOfInterest $area): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $attribute = $request?->attributes->get('_route');
        $route = \is_string($attribute) ? $attribute : '';
        $path = $request?->getPathInfo() ?? '/';
        $uuid = ['uuid' => $area->getUuidString()];

        $current = $this->currentArea($request);
        $inThisArea = null !== $current && $current->getId() === $area->getId();

        $here = $inThisArea ? $this->whereWeAre($route, $path) : null;
        if ($inThisArea && null === $here) {
            // WE DO NOT KNOW WHERE WE ARE, SO WE DO NOT CLAIM TO. A strip whose
            // job is to say which of these screens you are on, and which cannot
            // say it, is worse than no strip: it would light nothing and read
            // as four links to somewhere else. The shell refuses such a list on
            // purpose; this is the honest way to give it none.
            return [];
        }

        $tabs = [];
        foreach ($this->rows() as [$label, $routeName, $granted]) {
            $url = $granted ? $this->url($routeName, $uuid) : null;
            if (null === $url) {
                continue;
            }

            $tabs[] = new AreaTab(label: $label, url: $url, current: $label === $here);
        }

        // The screen we are on turned out to be one this viewer may not reach —
        // possible only if a permission changed mid-session. Say nothing rather
        // than light nothing.
        return !$inThisArea || $this->lights($tabs) ? $tabs : [];
    }

    /**
     * WHICH OF THE AREA'S SCREENS THIS REQUEST IS ON, by name — or null if it
     * is on none of them.
     *
     * The modules space is recognised by the URL space it owns rather than by a
     * route-name allowlist, and that is what keeps the application module-blind:
     * a module's own pages are its bundle's routes (`patrol_*`, `incident_*`),
     * and the host must light "Modules" for all of them without knowing one of
     * their names. Every area-scoped module page lives under
     * `/areas/{uuid}/modules/`, which is the one contract they all share.
     */
    private function whereWeAre(string $route, string $path): ?string
    {
        if (1 === preg_match('#^/areas/[^/]+/modules(?:/|$)#', $path)) {
            return 'Modules';
        }

        foreach ([
            'Overview' => ['dashboard_area_show'],
            'Zones' => ['app_area_zone'],
            'Settings' => ['dashboard_area_settings'],
        ] as $label => $prefixes) {
            if ($this->lit($route, $prefixes)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param list<AreaTab> $tabs
     */
    private function lights(array $tabs): bool
    {
        foreach ($tabs as $tab) {
            if ($tab->current) {
                return true;
            }
        }

        return false;
    }

    /**
     * The area's name, for the page title's middle segment. Null when the
     * request is not inside an area at all — the register, the departments
     * board, the team screen.
     */
    public function place(): ?string
    {
        $name = $this->currentArea($this->requestStack->getCurrentRequest())?->getName();

        return null === $name ? null : (string) $name;
    }

    /**
     * The area's screens, in the order they are shown, each with the routes
     * that light it and the permission that grants it.
     *
     * ZONES CARRIES NO GATE OF ITS OWN, exactly like the hub: a zone is a lens,
     * and a lens nobody may look through explains nothing. Reading the zoning
     * scheme is for anyone who can reach the area; importing, renaming and
     * deleting are gated inside the page itself.
     *
     * @return list<array{string, string, bool}>
     */
    private function rows(): array
    {
        return [
            ['Overview', 'dashboard_area_show', true],
            ['Modules', 'dashboard_area_modules_grid', $this->authorization->isGranted('module.view')],
            ['Zones', 'app_area_zones', true],
            ['Settings', 'dashboard_area_settings', $this->authorization->isGranted('area.edit')],
        ];
    }

    /**
     * @param list<string> $prefixes
     */
    private function lit(string $route, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function currentArea(?Request $request): ?AreaOfInterest
    {
        $uuid = $request?->attributes->get('uuid');
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }

    /**
     * A URL for a route that may not be registered — a slice of the app that
     * has not merged yet.
     *
     * @param array<string, string|null> $parameters
     */
    private function url(string $name, array $parameters = []): ?string
    {
        if (null === $this->router->getRouteCollection()->get($name)) {
            return null;
        }

        return $this->router->generate($name, array_filter(
            $parameters,
            static fn (?string $value): bool => null !== $value,
        ), UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
