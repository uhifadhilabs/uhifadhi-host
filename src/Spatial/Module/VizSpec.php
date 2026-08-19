<?php

declare(strict_types=1);

namespace Uhifadhi\Spatial\Module;

/**
 * A default visualization a module ships with: chart type (a VizType value — kept as string here so
 * the shared kernel needs no Composition dependency), the dataset key it binds, and its x/y columns.
 * Seeded ONCE per area-module as an editable Visualization row; users may change or delete it freely.
 */
final readonly class VizSpec
{
    public function __construct(
        public string $title,
        public string $type,
        public string $datasetKey,
        public string $x,
        public string $y,
    ) {
    }
}
