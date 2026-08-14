<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

/**
 * Run a module's data ingestion for one area: hand the area's geometry (and any tuning params) to the
 * geoprocessing engine, then land whatever datasets it returns in the generic per-module store. One
 * message per (area, module); the engine decides what datasets the module produces.
 */
final readonly class RunModuleIngestion
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public int $areaId,
        public string $moduleSlug,
        public array $params = [],
    ) {
    }
}
