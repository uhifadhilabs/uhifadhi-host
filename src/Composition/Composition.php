<?php

declare(strict_types=1);

namespace Uhifadhi\Composition;

/**
 * Marker for the Composition bounded context — how an area's sub-app is *composed*: which
 * analytical modules are active on an area, in what order, and how each module's
 * visualizations are configured.
 *
 * The module catalogue (formerly a static array in the Dashboard layer) becomes data here:
 *   - Entity\Module        the catalogue definition (Forest loss, Vegetation, …)
 *   - Entity\AreaModule    a module activated on one area, with its sort order
 *   - Entity\Visualization a configured chart on an area's module
 *
 * May depend on Spatial (areas) + Foundation. Dashboard composes it into the UI.
 *
 * Structural marker only.
 */
final class Composition
{
}
