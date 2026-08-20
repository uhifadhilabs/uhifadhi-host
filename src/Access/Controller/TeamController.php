<?php

declare(strict_types=1);

namespace Uhifadhi\Access\Controller;

use Uhifadhi\Access\Entity\Position;
use Uhifadhi\Access\Entity\User;
use Uhifadhi\Access\Form\PositionType;
use Uhifadhi\Access\Model\Permission;
use Uhifadhi\Access\Repository\PositionRepository;
use Uhifadhi\Access\Service\PermissionCatalogueService;
use Uhifadhi\Access\Service\TeamService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The /team admin screen: the roster and the position catalogue, plus the mutations behind them.
 * Team administration is Manager-and-up (access_control ^/team = ROLE_MANAGER, restated here for
 * defence in depth). Positions only apply to Staff — managing tiers hold everything by tier.
 */
#[Route('/team')]
#[IsGranted('ROLE_MANAGER')]
final class TeamController extends AbstractController
{
    public function __construct(
        private readonly TeamService $team,
        private readonly PositionRepository $positions,
        private readonly PermissionCatalogueService $permissionCatalogue,
    ) {
    }

    #[Route('', name: 'app_team', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('team/index.html.twig', [
            'members' => $this->team->members(),
            'positions' => $this->team->positions(),
        ]);
    }

    #[Route('/positions/new', name: 'app_team_position_new', methods: ['GET', 'POST'])]
    public function positionNew(Request $request): Response
    {
        $form = $this->createForm(PositionType::class, ['name' => '']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->team->createPosition($this->nameFrom($form), $this->submittedPermissions($request));
            $this->addFlash('success', 'Position created.');

            return $this->redirectToRoute('app_team');
        }

        return $this->render('team/position_form.html.twig', [
            'form' => $form,
            'heading' => 'New position',
            'catalogue' => $this->catalogue(),
            'checked' => $request->isMethod('POST') ? $this->submittedValues($request) : [],
            'locked' => false,
        ]);
    }

    #[Route('/positions/{uuid}/edit', name: 'app_team_position_edit', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function positionEdit(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Position $position,
        Request $request,
    ): Response {
        $form = $this->createForm(PositionType::class, ['name' => $position->getName()], ['locked' => $position->isLocked()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->team->updatePosition($position, $this->nameFrom($form), $this->submittedPermissions($request));
            $this->addFlash('success', 'Position updated.');

            return $this->redirectToRoute('app_team');
        }

        $checked = $request->isMethod('POST')
            ? $this->submittedValues($request)
            : $position->getPermissionValues();

        return $this->render('team/position_form.html.twig', [
            'form' => $form,
            'heading' => \sprintf('Edit “%s”', (string) $position->getName()),
            'catalogue' => $this->catalogue(),
            'checked' => $checked,
            'locked' => $position->isLocked(),
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

        $this->team->deletePosition($position);
        $this->addFlash('success', 'Position deleted; its holders were unassigned.');

        return $this->redirectToRoute('app_team');
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

    /** The submitted name — a non-empty string once the form is valid (NotBlank). */
    private function nameFrom(FormInterface $form): string
    {
        $name = $form->get('name')->getData();

        return \is_string($name) ? $name : '';
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

    /**
     * The raw submitted permission values (for re-ticking the matrix on validation error).
     *
     * @return list<string>
     */
    private function submittedValues(Request $request): array
    {
        return $this->submittedPermissions($request);
    }

    private function positionFromRequest(Request $request): ?Position
    {
        $uuid = $request->request->get('position');
        if (!\is_string($uuid) || '' === $uuid || !Uuid::isValid($uuid)) {
            return null; // empty selection → unassign
        }

        return $this->positions->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
