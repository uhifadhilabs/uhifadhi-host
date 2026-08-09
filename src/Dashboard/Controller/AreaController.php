<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Form\AreaUploadType;
use App\Forest\Repository\ForestLossYearRepository;
use App\Forest\Service\LossYearPaletteService;
use App\Ingestion\Message\IngestHansenLoss;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Exception\BoundaryImportException;
use App\Spatial\Repository\AreaOfInterestRepository;
use App\Spatial\Service\BoundaryImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

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
        ForestLossYearRepository $loss,
        DatasetRunRepository $runs,
    ): Response {
        $rows = [];
        foreach ($areas->findBy([], ['id' => 'ASC']) as $area) {
            $lossRows = $loss->findBy(['aoi' => $area]);
            $totalHa = 0.0;
            foreach ($lossRows as $lossRow) {
                $totalHa += $lossRow->getAreaHa() ?? 0.0;
            }
            $rows[] = [
                'area' => $area,
                'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
                'lossYears' => \count($lossRows),
                'lossTotalHa' => (int) round($totalHa),
                'lastRun' => $runs->findOneBy(['aoi' => $area], ['id' => 'DESC']),
            ];
        }

        return $this->render('dashboard/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/areas/new', name: 'dashboard_area_new', methods: ['GET', 'POST'])]
    public function new(Request $request, BoundaryImportService $importer): Response
    {
        $form = $this->createForm(AreaUploadType::class);
        $form->handleRequest($request);

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

                    return $this->redirectToRoute('dashboard_area_show', ['id' => $area->getId()]);
                } catch (BoundaryImportException $e) {
                    $form->get('boundaryFile')->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
                }
            }
        }

        return $this->render('dashboard/new.html.twig', ['form' => $form]);
    }

    #[Route('/areas/{id}', name: 'dashboard_area_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        AreaOfInterestRepository $areas,
        ForestLossYearRepository $loss,
        DatasetRunRepository $runs,
        LossYearPaletteService $palette,
    ): Response {
        $area = $areas->find($id) ?? throw $this->createNotFoundException();

        $geom = $area->getGeom();
        $boundary = [
            'type' => 'FeatureCollection',
            'features' => null === $geom ? [] : [[
                'type' => 'Feature',
                'properties' => ['name' => $area->getName()],
                'geometry' => json_decode($geom, true, 512, \JSON_THROW_ON_ERROR),
            ]],
        ];

        $lossRows = $loss->findBy(['aoi' => $area], ['year' => 'ASC']);
        $totalHa = 0.0;
        $lossByYear = [];
        $maxHa = 0.0;
        foreach ($lossRows as $row) {
            $ha = $row->getAreaHa() ?? 0.0;
            $totalHa += $ha;
            $maxHa = max($maxHa, $ha);
            $lossByYear[] = [
                'year' => (int) $row->getYear(),
                'ha' => $ha,
                'color' => $palette->colorFor((int) $row->getYear()),
            ];
        }

        $areaRuns = $runs->findBy(['aoi' => $area], ['id' => 'DESC'], 10);

        return $this->render('dashboard/show.html.twig', [
            'area' => $area,
            'boundary' => $boundary,
            'lossByYear' => $lossByYear,
            'maxLossHa' => $maxHa,
            'runs' => $areaRuns,
            'hasRunningRun' => [] !== array_filter($areaRuns, static fn ($run) => 'running' === $run->getStatus()),
            'stats' => [
                'areaKm2' => (int) round($areas->stAreaKm2(['id' => $area->getId()])),
                'totalLossHa' => (int) round($totalHa),
                'yearFrom' => [] !== $lossRows ? $lossRows[array_key_first($lossRows)]->getYear() : null,
                'yearTo' => [] !== $lossRows ? $lossRows[array_key_last($lossRows)]->getYear() : null,
            ],
        ]);
    }

    #[Route('/areas/{id}/ingest', name: 'dashboard_area_ingest', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ingest(int $id, Request $request, AreaOfInterestRepository $areas, MessageBusInterface $bus): Response
    {
        $area = $areas->find($id) ?? throw $this->createNotFoundException();
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('ingest'.$id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Routed async — the worker runs the minutes-long ETL; this returns now.
        $bus->dispatch(new IngestHansenLoss(aoiId: $id));
        $this->addFlash('success', \sprintf(
            'Hansen ingestion started for "%s" — the run appears below and the map updates when it finishes.',
            (string) $area->getName(),
        ));

        return $this->redirectToRoute('dashboard_area_show', ['id' => $id]);
    }
}
