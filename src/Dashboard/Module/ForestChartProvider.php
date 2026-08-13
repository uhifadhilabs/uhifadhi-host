<?php

declare(strict_types=1);

namespace App\Dashboard\Module;

use App\Composition\Entity\Visualization;
use App\Forest\Service\ForestChartService;
use App\Forest\Service\ForestLossSummaryService;
use App\Ingestion\Repository\DatasetRunRepository;
use App\Spatial\Entity\AreaOfInterest;
use Twig\Environment;

/**
 * Renders a Forest-loss visualization from the real Hansen series. The module's canonical analytical
 * charts (annual bar, cumulative, waterfall decomposition, LOESS, coverage gantt, shelf step) each
 * know how to draw themselves; a visualization whose type isn't one of these (e.g. a freshly-built
 * custom X-vs-Y plot) returns null until the generic plot engine lands, so its card shows a scaffold.
 */
final class ForestChartProvider implements ModuleChartProvider
{
    /** @var array<int, array{lossByYear: list<array{year: int, ha: float, color: string}>, statuses: list<string>}> keyed by area id */
    private array $cache = [];

    public function __construct(
        private readonly ForestLossSummaryService $forestLoss,
        private readonly ForestChartService $charts,
        private readonly DatasetRunRepository $runs,
        private readonly Environment $twig,
    ) {
    }

    public function slug(): string
    {
        return 'forest';
    }

    public function render(AreaOfInterest $area, Visualization $viz): ?string
    {
        $data = $this->dataFor($area);
        if ([] === $data['lossByYear']) {
            return null;
        }

        return match ($viz->getType()->value) {
            'bar' => $this->twig->render('forest/_annual_loss_chart.html.twig', ['series' => $data['lossByYear']]),
            'area' => $this->charts->cumulative($data['lossByYear']),
            'waterfall' => $this->charts->waterfall($data['lossByYear']),
            'lowess' => $this->charts->trend($data['lossByYear']),
            'gantt' => $this->charts->coverage($data['lossByYear']),
            'step' => $this->charts->shelf($data['statuses']),
            default => null, // custom/other configs — scaffold until the generic plot engine exists
        };
    }

    /**
     * @return array{lossByYear: list<array{year: int, ha: float, color: string}>, statuses: list<string>}
     */
    private function dataFor(AreaOfInterest $area): array
    {
        $id = (int) $area->getId();
        if (!isset($this->cache[$id])) {
            $forest = $this->forestLoss->forArea($area);
            $this->cache[$id] = [
                'lossByYear' => $forest['lossByYear'],
                'statuses' => array_map(
                    static fn ($run) => (string) $run->getStatus(),
                    $this->runs->findBy(['aoi' => $area], ['id' => 'DESC']),
                ),
            ];
        }

        return $this->cache[$id];
    }
}
