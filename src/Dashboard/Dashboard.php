<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Marker for the Dashboard bounded context — the UI composition layer.
 *
 * The pages compose every other context (areas from Spatial, metrics from
 * topics like Forest, runs/actions from Ingestion), so Dashboard is the ONE
 * layer allowed to depend on all of them — and nothing may depend on Dashboard.
 * It holds controllers, forms and templates only; domain logic stays in the
 * contexts it composes.
 */
final class Dashboard
{
}
