# Uhifadhi — Vision

_Captured 2026-08-08 from a product discussion; parked deliberately. Revisit when
the multi-area milestone is done._

## The one-liner

**The self-hostable conservation observability platform** — "Grafana for nature
conservation," except vertically integrated: Uhifadhi owns the data (PostGIS),
runs the domain pipelines (ingestion, provenance, geodesic math), and then
visualizes — the layer Grafana deliberately doesn't have. Grafana can chart a
`forest_loss_ha` column; it can never *produce* one. Uhifadhi does both.

## The gap in the world

| Existing | Why it isn't this |
|---|---|
| Global Forest Watch (WRI) | A website, not software an org can run on its own areas/data; forest-only |
| EarthRanger | Wildlife-ops focus, hosted, invite-based |
| Google Earth Engine | A compute API for programmers, not a product |
| Grafana + DIY glue | The org must build GDAL/PostGIS/scheduler pipelines itself — the exact pain to sell against |

Nobody ships **"install, upload your boundary, enable modules"** conservation
software. Tanzania alone: 22 TANAPA parks, the NCAA, dozens of WMAs.

## Why the current architecture already is this product

Nothing needs rearchitecting — the conventions in place are the module system:

- **Bounded contexts = modules.** `Forest` (Hansen loss) is module #1. The
  sidebar's "Soon" entries are the roadmap: **Fire hazards** (NASA FIRMS active
  fires — free, near-real-time, spectacular second module), Drainage,
  Invasives, Wildlife. Each: entities + ingestion adapter + map layers +
  metrics, deptrac-isolated, depending only on the Spatial kernel.
- **Ingestion context = the data-source plugin system.** Adapters (Hansen
  first; FIRMS/WorldCover/Sentinel next) behind one shape: AOI in → rows +
  `DatasetRun` provenance out.
- **fundistadi bundles = the platform SDK** (postgis-bundle, gdal-bundle —
  what an "Uhifadhi Labs" publishes).
- **Areas = the tenant seed.** Multi-area (in progress) → organizations →
  auth → per-org module enablement → alerting → optional hosted offering
  (the Grafana Labs open-core path, which also fits conservation-NGO funding).

## The warning (from Grafana's own history)

Do **not** drift into building a generic dashboard builder — that ends at
"worse Grafana." The product is that enabling a module gives an organization a
**scientifically correct, provenance-tracked pipeline** with its visualization,
in one click. Panels are commodity; verified domain pipelines are the moat.

## Naming

- **Uhifadhi** (Swahili: *conservation*, the practice) — the product.
- **DNCA** — the commissioning initiative and first deployment (Ngorongoro is
  simply the first area in the index, not a hardcoded assumption).
- **"Uhifadhi Labs"** — mirror of Grafana Labs; keep repos under `fundistadi`
  until a second product or a hosted offering makes "Labs" real.
