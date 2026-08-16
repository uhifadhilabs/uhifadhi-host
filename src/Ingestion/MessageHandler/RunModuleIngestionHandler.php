<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Message\RunModuleIngestion;
use App\Ingestion\Repository\DatasetRepository;
use App\Ingestion\Service\SpatialFeatureIngestor;
use App\Spatial\Entity\AreaOfInterest;
use App\Spatial\Repository\AreaOfInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The write path of the engine contract, and the generic successor to the bespoke Hansen handler: it
 * POSTs the area's geometry to the geoprocessing engine, then upserts every dataset the engine returns
 * into the generic per-module store — so a re-run replaces a module's data in place. All geospatial work
 * lives in the engine (a separate container); this handler only orchestrates and persists.
 *
 * Every run writes a DatasetRun for provenance: succeeded with the engine's report, or failed with the
 * error, re-thrown so the Messenger worker retries. The engine is reached over the scoped `engine.client`.
 */
#[AsMessageHandler]
final class RunModuleIngestionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $engineClient,
        private readonly AreaOfInterestRepository $areas,
        private readonly DatasetRepository $datasets,
        private readonly SpatialFeatureIngestor $spatialFeatures,
        #[Autowire('%env(ENGINE_TOKEN)%')]
        private readonly string $engineToken,
    ) {
    }

    public function __invoke(RunModuleIngestion $message): void
    {
        // A spatial module hands over a full-AOI GeoJSON (many MB) that toArray() decodes in one go;
        // the worker/CLI may default to a small memory_limit — lift it as the Hansen ETL does.
        if ('-1' !== ini_get('memory_limit')) {
            ini_set('memory_limit', '1G');
        }

        $area = $this->areas->find($message->areaId)
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d not found.', $message->areaId));

        $run = (new DatasetRun())
            ->setDataset($message->moduleSlug)
            ->setAoi($area)
            ->setParams([
                'areaId' => $message->areaId,
                'moduleSlug' => $message->moduleSlug,
                'params' => $message->params,
            ])
            ->setStartedAt(new \DateTimeImmutable());
        $this->em->persist($run);
        $this->em->flush();
        $runId = (int) $run->getId();

        try {
            $result = $this->callEngine($area, $message);
            $report = $result['run'] ?? null;
            $reportArray = \is_array($report) ? $this->stringKeyed($report) : null;
            // store() may clear the EntityManager (the spatial ingest stages in batches), detaching
            // $run — so finish the run by re-fetching it by id, as the Hansen ETL does.
            $this->store($area, $message->moduleSlug, $result, $run);
            $this->finishRun($runId, static function (DatasetRun $run) use ($reportArray): void {
                $run->setStatus(DatasetRun::STATUS_SUCCEEDED)->setReport($reportArray);
            });
        } catch (\Throwable $e) {
            $this->finishRun($runId, static function (DatasetRun $run) use ($e): void {
                $run->setStatus(DatasetRun::STATUS_FAILED)->setError($e->getMessage());
            });

            throw $e;
        }
    }

    /**
     * Finish the run by id (it may have been detached by a clear() mid-run), stamping its end time.
     *
     * @param callable(DatasetRun): void $mutate
     */
    private function finishRun(int $runId, callable $mutate): void
    {
        $run = $this->em->find(DatasetRun::class, $runId);
        if (null === $run) {
            return;
        }
        $mutate($run);
        $run->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * @return array<array-key, mixed> the engine's raw JSON response — validated in {@see store()}
     */
    private function callEngine(AreaOfInterest $area, RunModuleIngestion $message): array
    {
        $geoJson = $area->getGeom()
            ?? throw new \RuntimeException(\sprintf('AreaOfInterest %d has no geometry.', $message->areaId));

        return $this->engineClient->request('POST', \sprintf('/run/%s', $message->moduleSlug), [
            'headers' => ['X-Engine-Token' => $this->engineToken],
            'json' => [
                // The bare geometry wrapped as a Feature — the engine reads `aoi["geometry"]`.
                'aoi' => [
                    'type' => 'Feature',
                    'properties' => new \stdClass(),
                    'geometry' => json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR),
                ],
                'params' => $message->params,
            ],
            'timeout' => 600,
        ])->toArray();
    }

    /**
     * Upsert each dataset the engine returned into the generic store, tagged with the run that produced
     * it. The engine's output is untrusted JSON, so every field is validated, not assumed.
     *
     * @param array<array-key, mixed> $result
     */
    private function store(AreaOfInterest $area, string $moduleSlug, array $result, DatasetRun $run): void
    {
        $report = $result['run'] ?? null;
        $source = \is_array($report) && \is_string($report['source'] ?? null) ? $report['source'] : '';

        $datasets = $result['datasets'] ?? null;
        if (!\is_array($datasets)) {
            return;
        }

        $written = [];
        foreach ($datasets as $dataset) {
            if (!\is_array($dataset)) {
                continue;
            }
            $key = $dataset['key'] ?? null;
            $kind = $dataset['kind'] ?? null;
            if (!\is_string($key) || !\is_string($kind)) {
                continue;
            }

            // A vector layer arrives as inline GeoJSON (raw polygons tagged by an attribute) — it goes
            // to the spatial pipeline (stage → PostGIS dissolve → module_feature), never the tabular
            // store. So it is deliberately NOT recorded in $written: any prior tabular twin is reconciled.
            $geojson = $dataset['geojson'] ?? null;
            if (\is_array($geojson)) {
                $this->spatialFeatures->ingest(
                    $area,
                    $moduleSlug,
                    $key,
                    $this->features($geojson, \is_string($dataset['attribute'] ?? null) ? $dataset['attribute'] : 'label'),
                    is_numeric($dataset['simplify'] ?? null) ? (float) $dataset['simplify'] : null,
                );
                // The spatial ingest clears the EntityManager, detaching $area and $run — re-acquire
                // managed copies so any dataset AFTER the vector one doesn't link stale entities.
                $area = $this->em->find(AreaOfInterest::class, (int) $area->getId()) ?? $area;
                $run = $this->em->find(DatasetRun::class, (int) $run->getId()) ?? $run;
                continue;
            }

            $path = $dataset['path'] ?? null;
            $data = $dataset['data'] ?? null;
            $meta = null;
            if ('raster' === $kind && \is_string($data)) {
                // A raster arrives inline as base64 (e.g. a PNG) with its overlay metadata.
                $meta = [
                    'format' => \is_string($dataset['format'] ?? null) ? $dataset['format'] : 'png',
                    'bounds' => \is_array($dataset['bounds'] ?? null) ? $dataset['bounds'] : null,
                ];
                if (\is_array($dataset['legend'] ?? null)) {
                    // The surface's own legend (label, min/max, colour ramp) — the app renders
                    // the gradient without knowing the module.
                    $meta['legend'] = $dataset['legend'];
                }
            }
            $this->datasets->upsert($area, $moduleSlug, $key)
                ->setKind(DatasetKind::from($kind))
                ->setColumns($this->stringList($dataset['columns'] ?? null))
                ->setRows($this->rowList($dataset['rows'] ?? null))
                ->setPath(\is_string($path) ? $path : null)
                ->setPayload(\is_string($data) ? $data : null)
                ->setMeta($meta)
                ->setSource($source)
                ->setRun($run);
            $written[$key] = true;
        }

        // A run REPLACES a module's data: drop any dataset this run no longer produces, so a change to
        // the engine's output shape (e.g. two tables merged into one) doesn't leave stale keys behind.
        $this->em->flush();
        foreach ($this->datasets->forModule($area, $moduleSlug) as $existing) {
            if (!isset($written[(string) $existing->getKey()])) {
                $this->em->remove($existing);
            }
        }
    }

    /**
     * Coerce an untrusted value into the column-name list a tabular dataset carries.
     *
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $columns = [];
        foreach ($value as $item) {
            $columns[] = \is_scalar($item) ? (string) $item : '';
        }

        return $columns;
    }

    /**
     * Coerce an untrusted engine FeatureCollection into labelled raw polygons for the spatial ingest.
     * Each feature's label comes from its `properties[$attribute]`; geometry is passed through untouched.
     *
     * @param array<array-key, mixed> $geojson
     *
     * @return list<array{label: string, geometry: array<string, mixed>}>
     */
    private function features(array $geojson, string $attribute): array
    {
        $features = $geojson['features'] ?? null;
        if (!\is_array($features)) {
            return [];
        }

        $out = [];
        foreach ($features as $feature) {
            if (!\is_array($feature) || !\is_array($feature['geometry'] ?? null)) {
                continue;
            }
            $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $label = $properties[$attribute] ?? null;
            if (!\is_scalar($label)) {
                continue;
            }
            $out[] = ['label' => (string) $label, 'geometry' => $feature['geometry']];
        }

        return $out;
    }

    /**
     * Coerce an untrusted value into the row matrix a tabular dataset carries.
     *
     * @return list<list<scalar|null>>|null
     */
    private function rowList(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $rows = [];
        foreach ($value as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = \is_scalar($cell) || null === $cell ? $cell : null;
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $array): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
