<?php

declare(strict_types=1);

namespace Uhifadhi\Dashboard\Module;

use Uhifadhi\Composition\Entity\Visualization;
use Uhifadhi\Composition\Enum\VizType;
use Uhifadhi\Dashboard\Chart\ChartSvgService;
use Uhifadhi\Ingestion\Entity\Dataset;
use Uhifadhi\Ingestion\Repository\DatasetRepository;
use Uhifadhi\Spatial\Entity\AreaOfInterest;

/**
 * The module-agnostic read path of the engine contract: renders any bound {@see Visualization} by
 * resolving its module's {@see Dataset} (by key), mapping the viz's xAxis/yAxis column names onto that
 * dataset's columns, and drawing SVG via the generic {@see ChartSvgService}. Returns null — so the card
 * shows a scaffold — when the viz is unbound, its dataset is absent, its type isn't chartable yet, or a
 * bound column doesn't exist. It needs no knowledge of any specific module — it IS the plot engine,
 * for every module.
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

        return $this->renderConfig($area, $moduleSlug, $viz->getType(), $key, $viz->getXAxis(), $viz->getYAxis());
    }

    /**
     * Draw an arbitrary (possibly unsaved) chart config — the configure modal's LIVE PREVIEW path:
     * same resolution and drawing as a stored visualization, minus the entity.
     */
    public function renderConfig(AreaOfInterest $area, string $moduleSlug, VizType $type, string $key, ?string $x, ?string $y): ?string
    {
        $dataset = $this->datasets->findOneFor($area, $moduleSlug, $key);
        if (null === $dataset) {
            return null;
        }

        // Histogram and box read ONE numeric column: the y column doubles as the (unused) label source.
        $points = $this->points($dataset, \in_array($type, [VizType::Histogram, VizType::Box], true) ? $y : $x, $y);
        if (null === $points || [] === $points) {
            return null;
        }

        return match ($type) {
            VizType::Bar => $this->charts->bar($points),
            VizType::Line => $this->charts->line($points),
            VizType::Area => $this->charts->area($points),
            VizType::Scatter => $this->charts->scatter($points),
            VizType::Pie => $this->charts->pie($points),
            VizType::Histogram => $this->charts->histogram($points),
            VizType::Box => $this->charts->box($points),
            VizType::Waterfall => $this->charts->waterfall($points),
            VizType::Step => $this->charts->step($points),
            VizType::Lowess => $this->charts->lowess($points),
            // Gantt needs (label, start, end) — three columns the two-axis form can't bind yet.
            VizType::Gantt => null,
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
