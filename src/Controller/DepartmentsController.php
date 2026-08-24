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

namespace Uhifadhi\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Module;
use Uhifadhi\Model\DepartmentsWidgets;
use Uhifadhi\Service\DepartmentService;
use Uhifadhi\Service\DepartmentsSurface;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * The org-wide departments surface: the widget dashboard, its library, and the handful of
 * writes that administer the org chart.
 *
 * READING is for everyone signed in — a department is a lens, and a lens nobody may look
 * through explains nothing. ADMINISTERING is Manager-and-up, exactly as /team is; the
 * templates get that same answer as `canManage` so the chrome and the endpoint never disagree.
 *
 * The surface is org-wide, so every widget-framework call passes a null area: one stored
 * layout per person, not one per area.
 */
#[Route('/departments')]
final class DepartmentsController extends AbstractController
{
    /** One id for every management write — they are one capability, held by one tier. */
    private const string MANAGE_TOKEN = 'department_manage';

    public function __construct(
        private readonly DepartmentsSurface $surface,
        private readonly DepartmentService $departments,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
    ) {
    }

    #[Route('', name: 'app_departments', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('departments/index.html.twig', [
            ...$this->surface->context(),
            'canManage' => $this->isGranted('ROLE_MANAGER'),
            'widgets' => $this->widgets->resolve(DepartmentsWidgets::catalog(), $this->userId()),
        ]);
    }

    /**
     * The widget library: every widget at full size, as the REAL partial, under the five headed
     * sections the catalogue declares. What you arrange here is exactly what the dashboard renders,
     * because both render the same partials from the same context.
     */
    #[Route('/widgets', name: 'app_departments_widgets', methods: ['GET'])]
    public function widgets(): Response
    {
        $catalog = DepartmentsWidgets::catalog();

        return $this->render('departments/widgets.html.twig', [
            ...$this->surface->context(),
            'canManage' => $this->isGranted('ROLE_MANAGER'),
            'sections' => WidgetService::sections($catalog, $this->widgets->resolve($catalog, $this->userId())),
            'presets' => $catalog->presets(),
            'customPresets' => $this->widgets->customPresets($catalog, $this->userId()),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog),
        ]);
    }

    #[Route('/widgets/save', name: 'app_departments_widgets_save', methods: ['POST'])]
    public function widgetsSave(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, DepartmentsWidgets::catalog());
    }

    /**
     * Adopt one of the five design directions wholesale. Unlike save and reset — which the
     * library's script drives and reads as status codes — this is a PLAIN FORM POST from the
     * preset strip, so it answers the way every other form on the site does: back to the
     * dashboard, where the new layout is the thing to look at. Anything the endpoint refuses
     * (an unknown design, a bad token) is returned untouched, so the two callers see one shape.
     */
    #[Route('/widgets/preset/{presetId}', name: 'app_departments_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPreset(Request $request, string $presetId): Response
    {
        $catalog = DepartmentsWidgets::catalog();

        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Your dashboard now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_departments',
        );
    }

    /**
     * Keep the dashboard as it stands, under a name of your own. Self-service like every other
     * widget write: your layout, your name for it, no role involved.
     */
    #[Route('/widgets/presets', name: 'app_departments_widgets_preset_create', methods: ['POST'])]
    public function widgetsPresetCreate(Request $request): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->createCustomPreset($request, DepartmentsWidgets::catalog()),
            'Saved. Your layout is in “My presets”.',
            'app_departments_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'app_departments_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetApply(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyCustomPreset($request, DepartmentsWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Your dashboard now follows your saved preset.',
            'app_departments',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'app_departments_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetRename(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->renameCustomPreset($request, DepartmentsWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
            'app_departments_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'app_departments_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetDelete(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->deleteCustomPreset($request, DepartmentsWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Preset deleted. Your dashboard is untouched.',
            'app_departments_widgets',
        );
    }

    #[Route('/widgets/reset', name: 'app_departments_widgets_reset', methods: ['POST'])]
    public function widgetsReset(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, DepartmentsWidgets::catalog());
    }

    #[Route('', name: 'app_department_create', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function create(Request $request): Response
    {
        $this->denyUnlessTokenValid($request);

        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            $this->addFlash('error', 'A department needs a name.');

            return $this->redirectToRoute('app_departments');
        }

        $this->departments->create($name);
        $this->addFlash('success', \sprintf('“%s” created.', $name));

        return $this->redirectToRoute('app_departments');
    }

    #[Route('/{uuid}/rename', name: 'app_department_rename', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function rename(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);

        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            $this->addFlash('error', 'A department needs a name.');

            return $this->redirectToRoute('app_departments');
        }

        $this->departments->rename($department, $name);
        $this->addFlash('success', \sprintf('Renamed to “%s”.', $name));

        return $this->redirectToRoute('app_departments');
    }

    /** A lens change only: the modules keep existing and nobody loses their job. */
    #[Route('/{uuid}/delete', name: 'app_department_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);

        $name = (string) $department->getName();
        $this->departments->delete($department);
        $this->addFlash('success', \sprintf('“%s” deleted; its positions were unfiled.', $name));

        return $this->redirectToRoute('app_departments');
    }

    /**
     * Attach the module, or detach it. One route rather than two because the matrix reading of
     * this screen is a grid of intersections, and an intersection has exactly one affordance.
     */
    #[Route(
        '/{uuid}/modules/{moduleUuid}/toggle',
        name: 'app_department_module_toggle',
        requirements: ['uuid' => Requirement::UUID, 'moduleUuid' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    public function toggleModule(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Department $department,
        #[MapEntity(mapping: ['moduleUuid' => 'uuid'])] Module $module,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($request);

        if ($department->hasModule($module)) {
            $this->departments->detachModule($department, $module);
        } else {
            $this->departments->attachModule($department, $module);
        }

        return $this->redirectToRoute('app_departments');
    }

    /**
     * The preset strip is plain forms, so a successful write answers the way every form on the
     * site does — a flash and a redirect (Post/Redirect/Get). A refusal is returned exactly as
     * the endpoint wrote it (404 for a preset that is not yours, 422 for a name or a layout the
     * framework will not store, 403 for a bad token), so the strip and a scripted caller get one
     * shape and this controller invents no status codes of its own.
     */
    private function afterPresetWrite(Response $response, string $flash, string $route): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $this->addFlash('success', $flash);

        return $this->redirectToRoute($route);
    }

    /** The signed-in person's id, whose layout this is. Null is impossible behind the firewall. */
    private function userId(): int
    {
        return $this->widgetEndpoint->userId();
    }

    private function denyUnlessTokenValid(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::MANAGE_TOKEN, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
