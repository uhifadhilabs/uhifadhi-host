<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Entity\DatasetRun;
use App\Ingestion\Enum\DatasetKind;
use App\Ingestion\Message\RunModuleIngestion;
use App\Ingestion\Repository\DatasetRepository;
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
        #[Autowire('%env(ENGINE_TOKEN)%')]
        private readonly string $engineToken,
    ) {
    }

    public function __invoke(RunModuleIngestion $message): void
    {
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

        try {
            $result = $this->callEngine($area, $message);
            $this->store($area, $message->moduleSlug, $result, $run);
            $report = $result['run'] ?? null;
            $run->setStatus(DatasetRun::STATUS_SUCCEEDED)->setReport(\is_array($report) ? $this->stringKeyed($report) : null);
        } catch (\Throwable $e) {
            $run->setStatus(DatasetRun::STATUS_FAILED)->setError($e->getMessage());
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();

            throw $e;
        }

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
            $path = $dataset['path'] ?? null;

            $this->datasets->upsert($area, $moduleSlug, $key)
                ->setKind(DatasetKind::from($kind))
                ->setColumns($this->stringList($dataset['columns'] ?? null))
                ->setRows($this->rowList($dataset['rows'] ?? null))
                ->setPath(\is_string($path) ? $path : null)
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
