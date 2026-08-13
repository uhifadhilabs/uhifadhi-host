<?php

declare(strict_types=1);

namespace App\Dashboard\Module;

use App\Composition\Entity\Visualization;
use App\Spatial\Entity\AreaOfInterest;

/**
 * Renders one module's visualizations on an area. Visualizations are dynamic — added, removed and
 * reconfigured freely on any module — so a provider renders whatever single {@see Visualization} it
 * is handed (not a fixed set), returning null when it can't draw that config yet (→ the grid shows a
 * scaffold). Each live module (Forest today; others as they light up) provides one implementation, so
 * the generic module-edit/module pages never need to know a specific module.
 */
interface ModuleChartProvider
{
    /** The module slug this provider renders for (e.g. "forest"). */
    public function slug(): string;

    /**
     * The rendered SVG for one visualization on this area, or null if it can't be drawn yet.
     */
    public function render(AreaOfInterest $area, Visualization $viz): ?string;
}
