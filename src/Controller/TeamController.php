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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\Department;
use Uhifadhi\Entity\Position;
use Uhifadhi\Entity\User;
use Uhifadhi\Enum\TeamRoleEnum;
use Uhifadhi\Form\MemberType;
use Uhifadhi\Form\PositionType;
use Uhifadhi\Model\Permission;
use Uhifadhi\Model\TeamWidgets;
use Uhifadhi\Model\WidgetDom;
use Uhifadhi\Repository\DepartmentRepository;
use Uhifadhi\Repository\PositionRepository;
use Uhifadhi\Service\PermissionCatalogueService;
use Uhifadhi\Service\TeamService;
use Uhifadhi\Service\WidgetEndpoint;
use Uhifadhi\Service\WidgetService;

/**
 * The org-wide TEAM surface: the widget dashboard, its library, and the writes that administer
 * the roster and the position catalogue.
 *
 * Team administration is Manager-and-up (access_control ^/team = ROLE_MANAGER, restated here for
 * defence in depth) — unlike /departments, /team is administration end to end, so there is no
 * read-only reading of it to let anyone else through to. Positions only apply to Staff; managing
 * tiers hold everything by tier.
 *
 * THE DEPARTMENT IS ALWAYS EXPLICIT. A position's name is unique inside its department only, so
 * every create route takes a department (a band you typed inside, a card's own button, a
 * required field one) and there is no inline "set the department" control on a position row —
 * moving a position between departments would silently re-scope its name. The one move that does
 * exist is {@see positionFile()}: out of the unfiled holding pen and into a real department.
 *
 * The surface is org-wide, so every widget-framework call passes a null area: one stored layout
 * per person, not one per area.
 */
#[Route('/team')]
#[IsGranted('ROLE_MANAGER')]
final class TeamController extends AbstractController
{
    /** One id for every inline position write — they are one capability, held by one tier. */
    private const string MANAGE_TOKEN = 'team_position_manage';

    public function __construct(
        private readonly TeamService $team,
        private readonly PositionRepository $positions,
        private readonly DepartmentRepository $departments,
        private readonly PermissionCatalogueService $permissionCatalogue,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
    ) {
    }

    /**
     * The dashboard: the person's own resolved layout, in their own order, with the widgets they
     * switched off simply absent.
     *
     * `department` and `rail` are the two positions directions that hold a selection (B's chips,
     * E's rail). They are query parameters rather than client state, so a scoped list is a URL
     * somebody can send and it survives a reload — which is precisely what makes a bare "Analyst"
     * unambiguous in those two designs.
     */
    #[Route('', name: 'app_team', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('team/index.html.twig', [
            ...$this->context($request),
            'widgets' => $this->widgets->resolve(TeamWidgets::catalog(), $this->userId()),
        ]);
    }

    /**
     * The widget library: every widget at full size, as the REAL partial, under the two headed
     * sections the catalogue declares. What you arrange here is exactly what the dashboard
     * renders, because both render the same partials from the same context.
     */
    #[Route('/widgets', name: 'app_team_widgets', methods: ['GET'])]
    public function widgets(Request $request): Response
    {
        $catalog = TeamWidgets::catalog();
        $userId = $this->userId();

        return $this->render('team/widgets.html.twig', [
            ...$this->context($request),
            // Everything templates/widgets/_library.html.twig is parameterised by — the shared
            // component, whole, over this surface's catalogue and this surface's routes. Nothing
            // in it knows the word "team", which is the point.
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $userId),
            'active' => $this->widgets->activeRef($catalog, $userId),
            'widgets' => $this->widgets->resolve($catalog, $userId),
            'partial' => 'team/_w_%s.html.twig',
            'urls' => $this->widgetUrls(),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog),
        ]);
    }

    #[Route('/widgets/save', name: 'app_team_widgets_save', methods: ['POST'])]
    public function widgetsSave(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, TeamWidgets::catalog());
    }

    /**
     * Adopt one of the five directions wholesale. Unlike save and reset — which the library's
     * script drives and reads as status codes — this is a PLAIN FORM POST from the preset strip,
     * so it answers the way every other form on the site does: back to the dashboard, where the
     * new layout is the thing to look at.
     */
    #[Route('/widgets/preset/{presetId}', name: 'app_team_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPreset(Request $request, string $presetId): Response
    {
        $catalog = TeamWidgets::catalog();

        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Your team dashboard now follows “%s”.', $catalog->preset($presetId)?->label),
            'app_team',
        );
    }

    /**
     * Make a copy of one of the five directions, to customize. The designs the surface ships are
     * immutable, so this is the only door from one into an editable layout — and the copy becomes
     * active, because customizing a design you are looking at means customizing the one you are on.
     */
    #[Route('/widgets/preset/{presetId}/copy', name: 'app_team_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function widgetsPresetCopy(Request $request, string $presetId): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->copyPreset($request, TeamWidgets::catalog(), $presetId),
            'Copied. The copy is yours to edit, and your dashboard is on it.',
            'app_team_widgets',
        );
    }

    #[Route('/widgets/presets', name: 'app_team_widgets_preset_create', methods: ['POST'])]
    public function widgetsPresetCreate(Request $request): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->createCustomPreset($request, TeamWidgets::catalog()),
            'Saved. Your layout is in “My presets”.',
            'app_team_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'app_team_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetApply(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->applyCustomPreset($request, TeamWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Your team dashboard now follows your saved preset.',
            'app_team',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'app_team_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetRename(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->renameCustomPreset($request, TeamWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
            'app_team_widgets',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'app_team_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    public function widgetsPresetDelete(Request $request, string $presetUuid): Response
    {
        return $this->afterPresetWrite(
            $this->widgetEndpoint->deleteCustomPreset($request, TeamWidgets::catalog(), Uuid::fromString($presetUuid)),
            'Preset deleted. Your dashboard is untouched.',
            'app_team_widgets',
        );
    }

    #[Route('/widgets/reset', name: 'app_team_widgets_reset', methods: ['POST'])]
    public function widgetsReset(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, TeamWidgets::catalog());
    }

    /**
     * Create a position from a widget — a band's create row, a card's own Add button, the flat
     * list's create form. Every one of them names the department FIRST, because the name the
     * position is about to take is only unique inside it; an empty `department` is the unfiled
     * holding pen, stated as deliberately as any other choice.
     *
     * The permission matrix is not on these affordances (it is a screen, not a field), so a
     * position created here starts with none and its Edit link is the next step. The exception is
     * direction D, whose create form draws the matrix inline and posts it here as permissions[].
     */
    #[Route('/positions', name: 'app_team_position_create', methods: ['POST'])]
    public function positionCreate(Request $request): Response
    {
        $this->denyUnlessManageTokenValid($request);

        $department = $this->departmentFromRequest($request);
        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            $this->addFlash('error', 'A position needs a name.');

            return $this->redirectToRoute('app_team');
        }
        if (!$this->team->nameIsFree($department, $name)) {
            $this->addFlash('error', $this->clash($department, $name));

            return $this->redirectToRoute('app_team');
        }

        $position = $this->team->createPosition($name, $this->submittedPermissions($request), $department);
        $this->addFlash('success', \sprintf('Created “%s”.', TeamService::qualified($position)));

        return $this->redirectToRoute('app_team');
    }

    /**
     * The full create screen — a name, its department, and the permission matrix. Reached from
     * the page header and from a band's "+ permissions" affordance, which passes the band's own
     * department so the screen opens already inside it.
     */
    #[Route('/positions/new', name: 'app_team_position_new', methods: ['GET', 'POST'])]
    public function positionNew(Request $request): Response
    {
        $form = $this->createForm(PositionType::class, ['name' => '']);
        $form->handleRequest($request);

        $department = $request->isMethod('POST')
            ? $this->departmentFromRequest($request)
            : $this->departmentFromQuery($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->nameFrom($form);
            if ($this->team->nameIsFree($department, $name)) {
                $position = $this->team->createPosition($name, $this->submittedPermissions($request), $department);
                $this->addFlash('success', \sprintf('Created “%s”.', TeamService::qualified($position)));

                return $this->redirectToRoute('app_team');
            }
            $this->addFlash('error', $this->clash($department, $name));
        }

        return $this->render('team/position_form.html.twig', [
            'form' => $form,
            'heading' => 'New position',
            'catalogue' => $this->catalogue(),
            'checked' => $request->isMethod('POST') ? $this->submittedPermissions($request) : [],
            'locked' => false,
            'departments' => $this->team->departments(),
            'department' => $department,
            'position' => null,
        ]);
    }

    #[Route('/positions/{uuid}/edit', name: 'app_team_position_edit', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function positionEdit(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Position $position,
        Request $request,
    ): Response {
        $form = $this->createForm(PositionType::class, ['name' => $position->getName()], ['locked' => $position->isLocked()]);
        $form->handleRequest($request);

        // The department this save is FOR — the one posted, which may be a move. A locked
        // position keeps the department it has, as it keeps its name.
        $department = $position->isLocked()
            ? $position->getDepartment()
            : $this->departmentFromRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->nameFrom($form);
            // The name is checked against the department the position will END UP IN. That is the
            // whole cost of allowing a move: a name that was unique where it was may collide
            // where it is going, and the reader has to be told before it lands.
            if ($position->isLocked() || $this->team->nameIsFree($department, $name, $position)) {
                $this->team->updatePosition($position, $name, $this->submittedPermissions($request), $department);
                $this->addFlash('success', \sprintf('Updated “%s”.', TeamService::qualified($position)));

                return $this->redirectToRoute('app_team');
            }
            $this->addFlash('error', $this->clash($department, $name));
        }

        $checked = $request->isMethod('POST')
            ? $this->submittedPermissions($request)
            : $position->getPermissionValues();

        return $this->render('team/position_form.html.twig', [
            'form' => $form,
            'heading' => \sprintf('Edit “%s”', TeamService::qualified($position)),
            'catalogue' => $this->catalogue(),
            'checked' => $checked,
            'locked' => $position->isLocked(),
            'departments' => $this->team->departments(),
            // On a refused save the select stays on what the person chose, not on what is stored.
            'department' => $request->isMethod('POST') ? $department : $position->getDepartment(),
            'position' => $position,
        ]);
    }

    #[Route('/positions/{uuid}/delete', name: 'app_team_position_delete', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function positionDelete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Position $position,
        Request $request,
    ): Response {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('team_position_delete_'.$position->getUuidString(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = TeamService::qualified($position);
        $this->team->deletePosition($position);
        $this->addFlash('success', \sprintf('Deleted “%s”; its holders were unassigned.', $name));

        return $this->redirectToRoute('app_team');
    }

    /**
     * File an UNFILED position under a real department — the holding pen's one real action, and
     * the only route that changes a position's department at all.
     *
     * It is one-way by design. Unfiled is a transition state, so leaving it is a move somebody
     * has been putting off; moving between two real departments is a different act entirely — it
     * re-scopes the name against a set of names nobody was looking at — and this screen does not
     * offer it. A position that truly belongs elsewhere is deleted and recreated where it lives.
     */
    #[Route('/positions/{uuid}/department', name: 'app_team_position_file', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function positionFile(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Position $position,
        Request $request,
    ): Response {
        $this->denyUnlessManageTokenValid($request);

        if (null !== $position->getDepartment()) {
            $this->addFlash('error', \sprintf('“%s” already belongs to a department.', TeamService::qualified($position)));

            return $this->redirectToRoute('app_team');
        }

        $department = $this->departmentFromRequest($request);
        if (null === $department) {
            $this->addFlash('error', 'Choose the department to file this position under.');

            return $this->redirectToRoute('app_team');
        }
        if (!$this->team->nameIsFree($department, (string) $position->getName(), $position)) {
            $this->addFlash('error', $this->clash($department, (string) $position->getName()));

            return $this->redirectToRoute('app_team');
        }

        $this->team->filePosition($position, $department);
        $this->addFlash('success', \sprintf('Filed under %s — its holders moved with it: “%s”.', (string) $department->getName(), TeamService::qualified($position)));

        return $this->redirectToRoute('app_team');
    }

    /**
     * ADD A COLLEAGUE — the one act the roster never offered, so an account could only be made
     * from a console the client does not have. Super Admin only: it hands out a TIER, and a tier
     * is the coarsest permission the app has (Admin and Manager may administer positions, not
     * mint the people who hold them).
     *
     * There is no mail flow in this deployment, so nothing is emailed and nothing is confirmed:
     * the account is verified from birth and the app generates the password, shown ONCE on the
     * answer page for the admin to hand over. That is why a successful create RENDERS rather
     * than redirects — a Post/Redirect/Get would throw the one copy of the password away.
     */
    #[Route('/members/new', name: 'app_team_member_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function memberNew(Request $request): Response
    {
        $form = $this->createForm(MemberType::class, ['email' => '', 'firstName' => '', 'lastName' => '']);
        $form->handleRequest($request);

        $tier = $this->tierFromRequest($request);
        $position = $this->positionFromRequest($request);
        $created = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $this->fieldFrom($form, 'email');
            if ($this->team->emailIsFree($email)) {
                $created = $this->team->createMember(
                    $email,
                    $this->fieldFrom($form, 'firstName'),
                    $this->fieldFrom($form, 'lastName'),
                    $tier,
                    $position,
                );
                $this->addFlash('success', \sprintf(
                    'Created %s as %s. Hand them the password below — it is shown once.',
                    $created['user']->getFullName(),
                    $tier->label(),
                ));
                // A fresh form: the screen's next job is the next colleague, not this one again.
                $form = $this->createForm(MemberType::class, ['email' => '', 'firstName' => '', 'lastName' => '']);
            } else {
                $this->addFlash('error', \sprintf('“%s” already belongs to a member of this team.', $email));
            }
        }

        return $this->render('team/member_form.html.twig', [
            'form' => $form,
            'tiers' => TeamRoleEnum::cases(),
            'tier' => $tier,
            'positions' => $this->team->positionsInBandOrder(),
            'position' => $position,
            'created' => $created,
        ]);
    }

    /**
     * REGENERATE A MEMBER'S PASSWORD — the recovery this deployment would otherwise not have.
     *
     * There is no mail flow, so there is no "forgot password" link to send: an account whose
     * generated password was never written down is simply locked out. This is the same act as
     * creation, performed on an account that already exists, and it carries creation's rules —
     * Super Admin only, generated by the app, nothing emailed, shown exactly once.
     *
     * It RENDERS rather than redirects for the same reason creation does: a Post/Redirect/Get
     * would throw the one copy of the password away.
     */
    #[Route('/members/{uuid}/password', name: 'app_team_member_password', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function memberPassword(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] User $member,
        Request $request,
    ): Response {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('team_member_password_'.$member->getUuidString(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $password = $this->team->regeneratePassword($member);
        $this->addFlash('success', \sprintf(
            'New password for %s. Hand it over below — it is shown once, and the old one no longer works.',
            $member->getFullName(),
        ));

        return $this->render('team/member_password.html.twig', [
            'member' => $member,
            'password' => $password,
        ]);
    }

    #[Route('/members/{uuid}/assign', name: 'app_team_member_assign', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function memberAssign(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] User $member,
        Request $request,
    ): Response {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('team_member_assign_'.$member->getUuidString(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Managing tiers hold everything by tier — a position on them would be meaningless.
        if (!$this->team->canManage($member)) {
            throw $this->createAccessDeniedException('This member holds their permissions by tier, not a position.');
        }

        $this->team->assignPosition($member, $this->positionFromRequest($request));
        $this->addFlash('success', \sprintf('Updated %s’s position.', $member->getFullName()));

        return $this->redirectToRoute('app_team');
    }

    /**
     * Everything the six partials read, plus the three things only the page can answer: whether
     * this person may administer, the CSRF token id every inline write on the surface carries,
     * and the permission catalogue direction D's create form draws inline.
     *
     * `manageToken` is the token's ID rather than a minted token: the partials are included with
     * `with_context: false`, so they cannot reach Twig's globals for it, and a template that is
     * handed the id mints its own with csrf_token() — one literal, spelled once, in this class.
     *
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        return [
            ...$this->team->context(
                $request->query->getString('department') ?: null,
                $request->query->getString('rail') ?: null,
            ),
            'canManage' => true, // the whole controller is ROLE_MANAGER; stated so a partial need not ask
            'manageToken' => self::MANAGE_TOKEN,
            'catalogue' => $this->catalogue(),
        ];
    }

    /**
     * THE LIBRARY'S WIRE, as URLs — the same map the departments surface hands its library, so
     * when templates/widgets/_library.html.twig lands this surface adopts it by including it and
     * deleting its own markup, with nothing else to change. A template carries
     * {@see WidgetDom::ID_PLACEHOLDER} where the id goes.
     *
     * @return array<string, string>
     */
    private function widgetUrls(): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;

        return [
            'save' => $this->generateUrl('app_team_widgets_save'),
            'reset' => $this->generateUrl('app_team_widgets_reset'),
            'preset' => $this->generateUrl('app_team_widgets_preset', ['presetId' => $id]),
            'copy' => $this->generateUrl('app_team_widgets_preset_copy', ['presetId' => $id]),
            'presets' => $this->generateUrl('app_team_widgets_preset_create'),
            'apply' => $this->generateUrl('app_team_widgets_preset_apply', ['presetUuid' => $id]),
            'rename' => $this->generateUrl('app_team_widgets_preset_rename', ['presetUuid' => $id]),
            'delete' => $this->generateUrl('app_team_widgets_preset_delete', ['presetUuid' => $id]),
            'dashboard' => $this->generateUrl('app_team'),
        ];
    }

    /**
     * The preset strip is plain forms, so a successful write answers the way every form on the
     * site does — a flash and a redirect (Post/Redirect/Get). A refusal is returned exactly as
     * the endpoint wrote it, so the strip and a scripted caller get one shape.
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

    /** The refusal, in the rule's own words — and naming the department it was checked against. */
    private function clash(?Department $department, string $name): string
    {
        return \sprintf(
            '%s already has a position called “%s”. A name only has to be unique inside its department, so pick another one here — or create it under a different department.',
            null !== $department ? (string) $department->getName() : 'The unfiled holding pen',
            $name,
        );
    }

    /** The submitted name — a non-empty string once the form is valid (NotBlank). */
    private function nameFrom(FormInterface $form): string
    {
        return $this->fieldFrom($form, 'name');
    }

    /** One submitted text field, trimmed — non-empty once the form is valid (NotBlank). */
    private function fieldFrom(FormInterface $form, string $field): string
    {
        $value = $form->get($field)->getData();

        return \is_string($value) ? trim($value) : '';
    }

    /** The submitted tier. An unknown or absent value is Staff — the tier that grants nothing by itself. */
    private function tierFromRequest(Request $request): TeamRoleEnum
    {
        return TeamRoleEnum::tryFrom($request->request->getString('tier')) ?? TeamRoleEnum::Staff;
    }

    /**
     * The permission catalogue grouped by umbrella, for the checkbox matrix —
     * the app's own permissions plus everything the installed modules declare.
     *
     * @return array<string, list<Permission>>
     */
    private function catalogue(): array
    {
        return $this->permissionCatalogue->groupedByUmbrella();
    }

    /**
     * The submitted permissions[], filtered to the known catalogue (core and
     * module-declared alike; unknown values are dropped).
     *
     * @return list<string>
     */
    private function submittedPermissions(Request $request): array
    {
        $values = [];
        foreach ($request->request->all('permissions') as $value) {
            if (\is_string($value)) {
                $values[] = $value;
            }
        }

        return $this->permissionCatalogue->knownValues($values);
    }

    private function departmentFromRequest(Request $request): ?Department
    {
        return $this->departmentByUuid($request->request->get('department'));
    }

    private function departmentFromQuery(Request $request): ?Department
    {
        return $this->departmentByUuid($request->query->get('department'));
    }

    private function departmentByUuid(mixed $uuid): ?Department
    {
        if (!\is_string($uuid) || '' === $uuid || !Uuid::isValid($uuid)) {
            return null; // empty selection → the unfiled holding pen
        }

        return $this->departments->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }

    private function positionFromRequest(Request $request): ?Position
    {
        $uuid = $request->request->get('position');
        if (!\is_string($uuid) || '' === $uuid || !Uuid::isValid($uuid)) {
            return null; // empty selection → unassign
        }

        return $this->positions->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }

    private function denyUnlessManageTokenValid(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::MANAGE_TOKEN, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
