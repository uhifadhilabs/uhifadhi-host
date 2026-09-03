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

namespace Uhifadhi\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Repository\AreaOfInterestRepository;
use UhifadhiLabs\Trunk\Repository\AreaModuleRepository;
use UhifadhiLabs\Trunk\Service\ModuleEntryRouteResolver;

/**
 * Builds the sidebar's LOCATION TREE (design ruling F): Areas ─ every area ─ the current area's
 * real tabs ─ that area's modules under Modules. It states where you are, and Modules is a parent
 * on every page of the area it belongs to — folded, never dropped, when you are elsewhere in it.
 *
 * Two rules keep it honest:
 *  - MODULE-BLIND. No module slug is named here. The rows under Modules are the area's own active
 *    AreaModule rows, each linked through its provider's entry route
 *    ({@see ModuleEntryRouteResolver}); the current one is found by matching the
 *    `/areas/{uuid}/modules/{slug}` URL space against them.
 *  - ROUTE-TOLERANT. Every route is resolved through the router's collection, so a surface that
 *    has not merged yet (Zones, the performance board) simply does not render its row instead of
 *    breaking every page in the app.
 *
 * @phpstan-type SidebarModule array{slug: string, name: string, url: string, current: bool}
 * @phpstan-type SidebarTab array{label: string, url: string, current: bool, parent: bool,
 *     open: bool, modules: list<SidebarModule>}
 * @phpstan-type SidebarArea array{name: string, url: string, current: bool, tabs: list<SidebarTab>}
 * @phpstan-type Sidebar array{onAreas: bool, treeOpen: bool, areas: list<SidebarArea>,
 *     performanceUrl: string|null, performance: bool, departments: bool, team: bool}
 */
final class SidebarRuntime implements RuntimeExtensionInterface
{
    /** The URL space every area-scoped module page lives in — the host's one module contract. */
    private const string MODULE_PATH = '#^/areas/[^/]+/modules/(?<slug>[^/]+)#';

    /** The whole Modules space of an area: the grid, the shop, and every module page under it. */
    private const string MODULES_PATH = '#^/areas/[^/]+/modules(?:/|$)#';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly AreaOfInterestRepository $areas,
        private readonly AreaModuleRepository $areaModules,
        private readonly ModuleEntryRouteResolver $entryRoutes,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @phpstan-return Sidebar
     */
    public function build(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $route = \is_string($request?->attributes->get('_route')) ? (string) $request->attributes->get('_route') : '';
        $path = $request?->getPathInfo() ?? '/';
        // Module screens are bundle routes (patrol_*, …), so the areas section is recognised by the
        // URL space it owns, never by a route-name allowlist — that is what keeps the host module-blind.
        $onAreas = str_starts_with($route, 'dashboard') || str_starts_with($path, '/areas');

        $current = $onAreas ? $this->currentArea($request) : null;

        $areas = [];
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            $isCurrent = null !== $current && $area->getId() === $current->getId();
            $areas[] = [
                'name' => (string) $area->getName(),
                'url' => (string) $this->url('dashboard_area_show', ['uuid' => $area->getUuidString()]),
                'current' => $isCurrent,
                'tabs' => $this->tabs($area, $isCurrent, $route, $path),
            ];
        }

        return [
            'onAreas' => $onAreas,
            'treeOpen' => $onAreas,
            'areas' => $areas,
            'performanceUrl' => $this->url('app_departments_performance'),
            'performance' => 'app_departments_performance' === $route,
            // Both the dashboard (app_departments…) and every management write (app_department_…)
            // share this prefix, so the item stays lit wherever the surface takes you — but the
            // performance board is its own item and must not light Departments too.
            'departments' => str_starts_with($route, 'app_department')
                && 'app_departments_performance' !== $route,
            'team' => str_starts_with($route, 'app_team'),
        ];
    }

    /**
     * The area's real tab row, verbatim and in order. Zones is a SIBLING of Modules, never under
     * it; the area's modules are the only thing that ever nests under Modules.
     *
     * Modules is a PARENT whenever its area is current — on every page, not only inside a module
     * (design: areas/ngorongoro/index.html draws `ntt par closed`, modules.html `ntt on par`,
     * modules/patrols/index.html `ntt par` + `.ntm.on`). Being folded is a state of the group,
     * never a reason to omit it: what folds must be able to reopen.
     *
     * @phpstan-return list<SidebarTab>
     */
    private function tabs(AreaOfInterest $area, bool $isCurrent, string $route, string $path): array
    {
        $uuid = ['uuid' => $area->getUuidString()];
        $modules = $isCurrent ? $this->modules($area, $path) : [];
        // Open exactly where the design opens it: anywhere inside the area's Modules space.
        $modulesOpen = $isCurrent && 1 === preg_match(self::MODULES_PATH, $path);

        $rows = [
            ['Overview', 'dashboard_area_show', ['dashboard_area_show'], true, [], false],
            ['Modules', 'dashboard_area_modules_grid', ['dashboard_area_modules_grid', 'dashboard_area_modules'], $this->authorization->isGranted('module.view'), $modules, $modulesOpen],
            ['Zones', 'app_area_zones', ['app_area_zone'], true, [], false],
            ['Settings', 'dashboard_area_settings', ['dashboard_area_settings'], $this->authorization->isGranted('area.edit'), [], false],
        ];

        $tabs = [];
        foreach ($rows as [$label, $routeName, $lights, $granted, $tabModules, $open]) {
            $url = $granted ? $this->url($routeName, $uuid) : null;
            if (null === $url) {
                continue;
            }

            $on = $isCurrent && $this->lit($route, $lights);
            $tabs[] = [
                'label' => $label,
                'url' => $url,
                // A parent never steals the active state from the child it is showing.
                'current' => $on && !$this->holdsCurrent($tabModules),
                'parent' => [] !== $tabModules,
                'open' => $open,
                'modules' => $tabModules,
            ];
        }

        return $tabs;
    }

    /**
     * Every module that hangs under the area's Modules tab: the area's own active modules, in the
     * area's order, each marked current when the request is inside it. Module-blind — no slug is
     * named here; a module only earns a row once its provider owns pages to link to (the design
     * draws no row for a module that has none).
     *
     * @phpstan-return list<SidebarModule>
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

            $modules[] = [
                'slug' => $slug,
                'name' => (string) $module->getName(),
                'url' => $url,
                'current' => $slug === $currentSlug,
            ];
        }

        return $modules;
    }

    /**
     * @phpstan-param list<SidebarModule> $modules
     */
    private function holdsCurrent(array $modules): bool
    {
        foreach ($modules as $module) {
            if ($module['current']) {
                return true;
            }
        }

        return false;
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
     * A URL for a route that may not be registered (a slice of the app that has not merged yet).
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
