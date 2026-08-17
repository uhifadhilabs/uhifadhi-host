<?php

declare(strict_types=1);

namespace App\Statistics\Message;

/** Recompute an area's Q6 synthesis from the other modules' stored datasets. */
final readonly class RefreshSynthesis
{
    public function __construct(
        public int $areaId,
    ) {
    }
}
