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
use Uhifadhi\Seam\Repository\AreaModuleRepository;
use Uhifadhi\Seam\Service\ModuleEntryRouteResolver;
use Uhifadhi\Shell\Contract\NavigationSourceInterface;
use Uhifadhi\Shell\Model\NavItem;
use Uhifadhi\Shell\Model\NavSection;

/**
 * WHAT GOES IN THE SIDEBAR — the application's whole answer, in one class.
 *
 * This is where the application's knowledge enters the shell. The shell owns the
 * nav's shape (sections, rows, the location tree, carets, the lit row, the
 * collapsed rail) and none of its content; folding the areas, the viewer, the
 * permission voters and the module seam's per-area ledger into "these rows, in
 * this order" needs all four, so it is the host's job and it is done here.
 *
 * THREE RULES KEEP IT HONEST.
 *
 * MODULE-BLIND. No module slug is named. The rows under an area's modules are
 * that area's own active rows from the seam's ledger, each linked through its
 * provider's declared entry route, and the one you are inside is found by
 * matching the `/areas/{uuid}/modules/{slug}` URL space rather than by
 * recognising a name.
 *
 * ROUTE-TOLERANT. Every route is resolved through the router's collection, so a
 * surface that has not merged yet contributes an inert row — visible, dimmed,
 * not a link — instead of breaking every page in the application.
 *
 * GATED HERE, NOT THERE. A row the viewer may not have is one this class does
 * not return. The shell holds no authorization service and asks nothing about
 * the viewer, which is what keeps permission interpretation in one place.
 */
final class HostNavigation implements NavigationSourceInterface
{
    /** The URL space every area-scoped module page lives in — the one module contract. */
    private const string MODULE_PATH = '#^/areas/[^/]+/modules/(?<slug>[^/]+)#';

    /** The whole modules space of an area: the grid, the shop, and every module page under it. */
    private const string MODULES_PATH = '#^/areas/[^/]+/modules(?:/|$)#';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly AreaOfInterestRepository $areas,
        private readonly AreaModuleRepository $areaModules,
        private readonly ModuleEntryRouteResolver $entryRoutes,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly AreaShellSource $areaShell,
    ) {
    }

    /**
     * @return list<NavSection>
     */
    public function sections(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $attribute = $request?->attributes->get('_route');
        $route = \is_string($attribute) ? $attribute : '';
        $path = $request?->getPathInfo() ?? '/';

        // Module screens are bundle routes, so the areas section is recognised
        // by the URL space it owns and never by a route-name allowlist.
        $inAreas = str_starts_with($route, 'dashboard') || str_starts_with($path, '/areas');

        return [
            new NavSection('Observatory', $this->observatory($request, $route, $path, $inAreas), position: 10),
            new NavSection('Organization', $this->organization($route), position: 20),
            new NavSection('System', $this->system($route), position: 30),
        ];
    }

    /**
     * The register, and the location tree under it: every area, the one you are
     * in unfolded to its real screens, and that area's modules hanging under
     * Modules — on every one of its pages, folded away until you are in that
     * space.
     *
     * @return list<NavItem>
     */
    private function observatory(?Request $request, string $route, string $path, bool $inAreas): array
    {
        $current = $inAreas ? $this->currentArea($request) : null;

        $areas = [];
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            $isCurrent = null !== $current && $area->getId() === $current->getId();
            $areas[] = new NavItem(
                label: (string) $area->getName(),
                url: $this->url('dashboard_area_show', ['uuid' => $area->getUuidString()]),
                // The area's row is lit when you are in it, and its children say
                // which of its screens: one path, drawn, rather than two claims.
                current: $isCurrent,
                open: $isCurrent,
                children: $this->screens($area, $path, $isCurrent),
            );
        }

        $rows = [
            new NavItem(
                label: 'Areas',
                url: $this->url('dashboard_index'),
                icon: 'lucide:map',
                current: 'dashboard_index' === $route,
                open: $inAreas,
                children: $areas,
            ),
        ];

        // The performance board is the C-suite surface, so it sits top level —
        // and degrades to a named-but-unbuilt row until its route lands.
        $performance = $this->url('app_departments_performance');
        $rows[] = new NavItem(
            label: 'Performance',
            url: $performance,
            icon: 'lucide:trending-up',
            hint: null === $performance ? 'Performance — coming soon' : null,
            current: 'app_departments_performance' === $route,
        );

        return $rows;
    }

    /**
     * The area's own screens, from the one source that decides them, with that
     * area's modules hanging under the modules row. The tab strip above the page
     * and this branch read the same list and cannot disagree.
     *
     * @return list<NavItem>
     */
    private function screens(AreaOfInterest $area, string $path, bool $isCurrent): array
    {
        // Another area's modules are not listed: the sidebar unfolds an area to
        // its SCREENS so you can reach one, and what it has installed is a
        // reading of that area you get by going there.
        $modules = $isCurrent ? $this->modules($area, $path) : [];
        $inModules = $isCurrent && 1 === preg_match(self::MODULES_PATH, $path);

        $screens = [];
        foreach ($this->areaShell->screensOf($area) as $tab) {
            $children = 'Modules' === $tab->label ? $modules : [];
            $screens[] = new NavItem(
                label: $tab->label,
                url: $tab->url,
                // A parent never steals the lit state from the child it is
                // showing: inside a module, the module's row is the answer.
                current: $tab->current && !$this->holdsCurrent($children),
                open: [] === $children || $inModules,
                children: $children,
            );
        }

        return $screens;
    }

    /**
     * Every module hanging under the area's modules row: that area's own active
     * modules, in the area's order, each marked current when the request is
     * inside it.
     *
     * Module-blind — no slug is named here. A module earns a row only once its
     * provider declares pages to link to.
     *
     * @return list<NavItem>
     */
    private function modules(AreaOfInterest $area, string $path): array
    {
        $currentSlug = 1 === preg_match(self::MODULE_PATH, $path, $matches) ? $matches['slug'] : null;

        $modules = [];
        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            $module = $areaModule->getModule();
            $slug = $module?->getSlug();
            if (null === $module || null === $slug) {
                continue;
            }

            $entryRoute = $this->entryRoutes->entryRouteFor($slug);
            $url = null === $entryRoute ? null : $this->url($entryRoute, ['uuid' => $area->getUuidString()]);
            if (null === $url) {
                continue;
            }

            $modules[] = new NavItem(
                label: (string) $module->getName(),
                url: $url,
                current: $slug === $currentSlug,
            );
        }

        return $modules;
    }

    /**
     * Departments are a lens everyone looks through, so the row is not gated the
     * way the team screen is — only its management chrome is, inside the page.
     *
     * @return list<NavItem>
     */
    private function organization(string $route): array
    {
        $rows = [
            new NavItem(
                label: 'Departments',
                url: $this->url('app_departments'),
                icon: 'lucide:building',
                // Both the board and every management write share this prefix,
                // so the row stays lit wherever the surface takes you — but the
                // performance board is its own row and must not light this too.
                current: str_starts_with($route, 'app_department') && 'app_departments_performance' !== $route,
            ),
        ];

        if ($this->authorization->isGranted('ROLE_MANAGER')) {
            $rows[] = new NavItem(
                label: 'Team',
                url: $this->url('app_team'),
                icon: 'lucide:users',
                current: str_starts_with($route, 'app_team'),
            );
        }

        return $rows;
    }

    /**
     * @return list<NavItem>
     */
    private function system(string $route): array
    {
        return [
            new NavItem(
                label: 'Files',
                url: $this->url('storage_files'),
                icon: 'lucide:images',
                hint: 'Files',
                current: str_starts_with($route, 'storage_files'),
            ),
            new NavItem(
                label: 'Alerts',
                url: null,
                icon: 'lucide:bell',
                hint: 'Alerts — planned (workflow + audit roadmap)',
            ),
        ];
    }

    /**
     * @param list<NavItem> $items
     */
    private function holdsCurrent(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->current) {
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
     * A URL for a route that may not be registered — a slice of the application
     * that has not merged yet.
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
