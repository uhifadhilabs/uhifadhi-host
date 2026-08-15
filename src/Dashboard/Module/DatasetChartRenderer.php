<?php

declare(strict_types=1);

namespace App\Dashboard\Module;

use App\Composition\Entity\Visualization;
use App\Composition\Enum\VizType;
use App\Dashboard\Chart\ChartSvgService;
use App\Ingestion\Entity\Dataset;
use App\Ingestion\Repository\DatasetRepository;
use App\Spatial\Entity\AreaOfInterest;

/**
 * The module-agnostic read path of the engine contract: renders any bound {@see Visualization} by
 * resolving its module's {@see Dataset} (by key), mapping the viz's xAxis/yAxis column names onto that
 * dataset's columns, and drawing SVG via the generic {@see ChartSvgService}. Returns null — so the card
 * shows a scaffold — when the viz is unbound, its dataset is absent, its type isn't chartable yet, or a
 * bound column doesn't exist. Unlike the per-module {@see ModuleChartProvider}s, this needs no knowledge
 * of any specific module; it is the generic plot engine those hardcoded drawers defer to.
 */
final class DatasetChartRenderer
{
    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly ChartSvgService $charts,
    ) {
    }

    public function render(AreaOfInterest $area, Visualization $viz): ?string
    {
        $moduleSlug = $viz->getAreaModule()?->getModule()?->getSlug();
        $key = $viz->getDatasetKey();
        if (!$viz->isBound() || null === $moduleSlug || null === $key) {
            return null;
        }

        $dataset = $this->datasets->findOneFor($area, $moduleSlug, $key);
        if (null === $dataset) {
            return null;
        }

        $points = $this->points($dataset, $viz->getXAxis(), $viz->getYAxis());
        if (null === $points || [] === $points) {
            return null;
        }

        return match ($viz->getType()) {
            VizType::Bar => $this->charts->bar($points),
            VizType::Line => $this->charts->line($points),
            VizType::Area => $this->charts->area($points),
            VizType::Scatter => $this->charts->scatter($points),
            VizType::Waterfall => $this->charts->waterfall($points),
            VizType::Step => $this->charts->step($points),
            VizType::Lowess => $this->charts->lowess($points),
            // Gantt (date ranges) and Box (distributions) need a data shape that isn't (x, y) points —
            // not drawable from a plain column pair yet, so the card scaffolds.
            VizType::Gantt, VizType::Box => null,
        };
    }

    /**
     * Map the viz's x/y column names onto the dataset and extract the (label, value) points, or null if
     * the dataset isn't tabular or a named column isn't present.
     *
     * @return list<array{label: string, value: float}>|null
     */
    private function points(Dataset $dataset, ?string $xColumn, ?string $yColumn): ?array
    {
        $columns = $dataset->getColumns();
        $rows = $dataset->getRows();
        if (null === $columns || null === $rows || null === $xColumn || null === $yColumn) {
            return null;
        }

        $xi = array_search($xColumn, $columns, true);
        $yi = array_search($yColumn, $columns, true);
        if (false === $xi || false === $yi) {
            return null;
        }

        $points = [];
        foreach ($rows as $row) {
            if (!\array_key_exists($xi, $row) || !\array_key_exists($yi, $row)) {
                continue;
            }
            $label = $row[$xi];
            $value = $row[$yi];
            $points[] = [
                'label' => \is_scalar($label) ? (string) $label : '',
                'value' => is_numeric($value) ? (float) $value : 0.0,
            ];
        }

        return $points;
    }
}
