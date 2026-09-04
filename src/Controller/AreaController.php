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
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Exception\BoundaryImportException;
use Uhifadhi\Form\AreaUploadType;
use Uhifadhi\Repository\AreaOfInterestRepository;
use Uhifadhi\Seam\Repository\AreaModuleRepository;
use Uhifadhi\Service\AreaCardService;
use Uhifadhi\Service\BoundaryImportService;

/**
 * The dashboard pages: areas index (home), boundary upload, and the per-area
 * detail with map, metrics, runs and the ingestion trigger. Lean composition
 * only — logic lives in the contexts this layer composes.
 */
final class AreaController extends AbstractController
{
    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(
        AreaOfInterestRepository $areas,
        AreaModuleRepository $areaModules,
        AreaCardService $cards,
    ): Response {
        $rows = [];
        foreach ($areas->findBy([], ['id' => 'ASC']) as $area) {
            $coords = null;
            $thumb = null;
            $geom = $area->getGeom();
            if (null !== $geom) {
                $bounds = $cards->bounds($geom);
                [$lon, $lat] = $cards->centroid($bounds);
                $coords = $cards->formatCoords($lat, $lon);
                $thumb = $cards->thumbnailUrl($bounds);
            }

            $rows[] = [
                'area' => $area,
                'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
                'coords' => $coords,
                'thumb' => $thumb,
                // Modules switched on for this area — the register's "live" signal.
                'liveModules' => $areaModules->count(['area' => $area, 'active' => true]),
            ];
        }

        // Only counts the register can actually move: a pill stuck at 0 by construction
        // is furniture. "Fire module" and "With alerts" were exactly that and are gone;
        // an alerts-capable module bundle brings its own pill back with a real count.
        $counts = ['all' => \count($rows), 'live' => 0, 'queued' => 0];
        foreach ($rows as $r) {
            if ($r['liveModules'] > 0) {
                ++$counts['live'];
            } else {
                ++$counts['queued'];
            }
        }

        return $this->render('dashboard/index.html.twig', ['rows' => $rows, 'counts' => $counts]);
    }

    #[Route('/areas/new', name: 'dashboard_area_new', methods: ['GET', 'POST'])]
    #[IsGranted('area.create')]
    public function new(Request $request, BoundaryImportService $importer): Response
    {
        $form = $this->createForm(AreaUploadType::class);
        $form->handleRequest($request);

        // A POST that exceeds post_max_size arrives EMPTY: the form never counts
        // as submitted. Say so — never a silent 200 (Turbo requires 422/redirect).
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->addError(new FormError(\sprintf(
                'The upload exceeds the server upload limit (%s) — the file never reached the application.',
                (string) \ini_get('post_max_size'),
            )));

            return $this->render('dashboard/new.html.twig', ['form' => $form], new Response(status: 422));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $form->get('name')->getData();
            $file = $form->get('boundaryFile')->getData();
            if (\is_string($name) && $file instanceof UploadedFile) {
                try {
                    $area = $importer->import(
                        $file->getPathname(),
                        $file->getClientOriginalName() ?: 'boundary',
                        $name,
                        'upload',
                    );
                    $this->addFlash('success', \sprintf('Area "%s" imported.', $name));

                    return $this->redirectToRoute('dashboard_area_show', ['uuid' => $area->getUuidString()]);
                } catch (BoundaryImportException $e) {
                    $form->get('boundaryFile')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('dashboard/new.html.twig', ['form' => $form]);
    }

    #[Route('/areas/{uuid}/settings', name: 'dashboard_area_settings', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('area.edit')]
    public function settings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        AreaOfInterestRepository $areas,
        AreaModuleRepository $areaModules,
    ): Response {
        return $this->render('dashboard/area_settings.html.twig', [
            'area' => $area,
            'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
        ]);
    }
}
