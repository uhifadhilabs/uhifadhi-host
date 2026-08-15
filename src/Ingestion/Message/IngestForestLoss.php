<?php

declare(strict_types=1);

namespace App\Ingestion\Message;

/**
 * Run the Hansen GFC forest-loss ingestion for one area of interest: clip the
 * lossyear granules to the AOI, polygonize, dissolve per year, and replace the
 * rows carrying `source` in forest_loss_year.
 */
final readonly class IngestForestLoss
{
    public function __construct(
        public int $aoiId,
        public string $version = 'GFC-2023-v1.11',
        public string $source = 'hansen',
        public float $simplifyDegrees = 0.0003,
    ) {
    }
}
