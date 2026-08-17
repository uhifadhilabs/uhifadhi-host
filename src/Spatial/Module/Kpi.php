<?php

declare(strict_types=1);

namespace App\Spatial\Module;

/**
 * One headline figure on a module's Overview cockpit: `PL·xx`-style index, label, the big value
 * with its unit, a sub-line of context, and whether the plate runs hot (accent).
 */
final readonly class Kpi
{
    public function __construct(
        public string $idx,
        public string $label,
        public string $value,
        public string $unit = '',
        public string $sub = '',
        public bool $hot = false,
    ) {
    }
}
