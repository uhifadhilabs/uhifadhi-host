<?php

declare(strict_types=1);

namespace Uhifadhi\Spatial\Module;

use Uhifadhi\Spatial\Entity\AreaOfInterest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The ONE app-side extension point of the module system. A module ships as a subclass in its own
 * bounded context (e.g. Uhifadhi\Forest\Module\ForestModule) overriding only what differs from the
 * defaults — a data-only module can ship with no overrides at all (or no class: the registry falls
 * back to {@see \Uhifadhi\Dashboard\Module\GenericModule}). Every concrete subclass is auto-tagged
 * `app.module` and collected by the registry; generic code NEVER names a module —
 * `if ($slug === 'forest')` is banned.
 */
#[AutoconfigureTag('app.module')]
abstract class ModuleDefinition
{
    abstract public function slug(): string;

    /**
     * Overview KPIs. Empty ⇒ the dashboard derives generic ones from the module's first tabular dataset.
     *
     * @return list<Kpi>
     */
    public function kpis(AreaOfInterest $area): array
    {
        return [];
    }

    /**
     * The charts this module ships with, seeded ONCE per area-module as editable Visualization rows
     * (guarded by AreaModule.vizSeeded — deleting them all doesn't resurrect them).
     *
     * @return list<VizSpec>
     */
    public function defaultVisualizations(): array
    {
        return [];
    }

    /** The Method-tab caption; null → the tab shows its pending shell. */
    public function methodCaption(): ?MethodCaption
    {
        return null;
    }

    /**
     * Label → colour for the module's map layer and legend; empty → the neutral fallback colour.
     *
     * @return array<string, string>
     */
    public function palette(): array
    {
        return [];
    }

    /** The dataset key of the module's dissolved map layer. */
    public function mapDatasetKey(): string
    {
        return $this->slug().'_map';
    }
}
