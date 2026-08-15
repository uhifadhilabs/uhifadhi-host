<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Form\AreaUploadType;
use App\Dashboard\Service\AreaCardService;
use App\Forest\Service\ForestLossSummaryService;
use App\Ingestion\Message\RunModuleIngestion;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Exception\BoundaryImportException;
use App\Spatial\Repository\AreaOfInterestRepository;
use App\Spatial\Service\BoundaryImportService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        DatasetRepository $datasets,
        DatasetRunRepository $runs,
        AreaCardService $cards,
    ): Response {
        $rows = [];
        foreach ($areas->findBy([], ['id' => 'ASC']) as $area) {
            // The forest series from the generic store: rows of (year, ha, cumulative_ha).
            $lossRows = $datasets->findOneFor($area, 'forest', 'forest_loss_year')?->getRows() ?? [];
            $totalHa = 0.0;
            $series = [];
            foreach ($lossRows as $lossRow) {
                $ha = is_numeric($lossRow[1] ?? null) ? (float) $lossRow[1] : 0.0;
                $totalHa += $ha;
                $series[] = $ha;
            }

            $coords = null;
            $thumb = null;
            $geom = $area->getGeom();
            if (null !== $geom) {
                $bounds = $cards->bounds($geom);
                [$lon, $lat] = $cards->centroid($bounds);
                $coords = $cards->formatCoords($lat, $lon);
                $thumb = $cards->thumbnailUrl($bounds);
            }

            $years = \count($lossRows);
            $rows[] = [
                'area' => $area,
                'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
                'coords' => $coords,
                'thumb' => $thumb,
                'lossYears' => $years,
                'lossTotalHa' => (int) round($totalHa),
                'haPerYr' => $cards->haPerYear($totalHa, $years),
                'delta' => $cards->recentDeltaPct($series),
                // Per-year ha, oldest→newest, for the register sparkline.
                'series' => $series,
                // One live analytical module (Forest) once a series exists; else queued.
                'liveModules' => $years > 0 ? 1 : 0,
                'lastRun' => $runs->findOneBy(['aoi' => $area], ['id' => 'DESC']),
            ];
        }

        $counts = ['all' => \count($rows), 'live' => 0, 'fire' => 0, 'alerts' => 0, 'queued' => 0];
        foreach ($rows as $r) {
            if ($r['liveModules'] > 0) {
                ++$counts['live'];
            } else {
                ++$counts['queued'];
            }
            // fire / alerts stay 0 until the Fire-module and Alerts contexts ship.
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
                (string) ini_get('post_max_size'),
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

    #[Route('/areas/{uuid}', name: 'dashboard_area_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        AreaOfInterestRepository $areas,
        DatasetRunRepository $runs,
        ForestLossSummaryService $forestLoss,
    ): Response {
        $geom = $area->getGeom();
        $boundary = [
            'type' => 'FeatureCollection',
            'features' => null === $geom ? [] : [[
                'type' => 'Feature',
                'properties' => ['name' => $area->getName()],
                'geometry' => json_decode($geom, true, 512, \JSON_THROW_ON_ERROR),
            ]],
        ];

        $loss = $forestLoss->forArea($area);
        $areaRuns = $runs->findBy(['aoi' => $area], ['id' => 'DESC'], 10);

        return $this->render('dashboard/show.html.twig', [
            'area' => $area,
            'boundary' => $boundary,
            'lossByYear' => $loss['lossByYear'],
            'maxLossHa' => $loss['maxHa'],
            'runs' => $areaRuns,
            'runCount' => \count($areaRuns),
            'hasRunningRun' => [] !== array_filter($areaRuns, static fn ($run) => 'running' === $run->getStatus()),
            'stats' => [
                'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
                'totalLossHa' => (int) round($loss['totalHa']),
                'yearFrom' => $loss['yearFrom'],
                'yearTo' => $loss['yearTo'],
                'worstYear' => $loss['worstYear'],
                'worstHa' => (int) round($loss['worstHa']),
            ],
        ]);
    }

    #[Route('/areas/{uuid}/settings', name: 'dashboard_area_settings', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('area.edit')]
    public function settings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        AreaOfInterestRepository $areas,
    ): Response {
        return $this->render('dashboard/area_settings.html.twig', [
            'area' => $area,
            'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
        ]);
    }

    #[Route('/areas/{uuid}/ingest', name: 'dashboard_area_ingest', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ingestion.run')]
    public function ingest(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        MessageBusInterface $bus,
    ): Response {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('ingest'.$area->getUuidString(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Routed async — the worker calls the engine's forest module; this returns now.
        $bus->dispatch(new RunModuleIngestion((int) $area->getId(), 'forest', ['display_factor' => 2]));
        $this->addFlash('success', \sprintf(
            'Hansen ingestion started for "%s" — the run appears below and the map updates when it finishes.',
            (string) $area->getName(),
        ));

        return $this->redirectToRoute('dashboard_area_show', ['uuid' => $area->getUuidString()]);
    }
}
