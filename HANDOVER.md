# uhifadhi — Project Handover & State

> **RENAMED 2026-08-08: `dnca` → `uhifadhi`** (Swahili: *conservation*). The app
> generalized to any user-provided boundary, so the product got a product name;
> **DNCA** stays as the commissioning initiative / first deployment, and
> Ngorongoro is simply the first area in the index. Folder is now
> `~/Programming/PhpstormProjects/uhifadhi`, app at `https://uhifadhi.localhost`,
> DBs `uhifadhi` / `uhifadhi_test` (fundi PostGIS-17 cluster, port 5434).
> Product vision ("Grafana for conservation") is parked in **`VISION.md`**.
> Historical "dnca" mentions below are pre-rename facts left as written.

_Last updated: **2026-08-09** (see **§8**, the full delta — read it first; §§1–7
are the 08-08 baseline). Self-contained: written for a fresh Claude Code session
started in THIS directory (session memory from the old `RStudioProjects/DNCA`
working dir does NOT follow — this file is the source of truth)._

---

## 1. What this project is

**dnca** is the proof-of-concept web app for the **Digitalization of the Ngorongoro
Conservation Area (DNCA)** initiative — a Symfony modular monolith that will grow
one bounded context per conservation topic.

**Background & proposals.** The initiative has ~12 candidate topics (settlement
growth, deforestation, drainage/hydrology, invasive species, wildlife, LiDAR
forest structure, …). The full analysis — per-topic problem/hypothesis/tech-stack,
plus "Appendix A: per-component language selection" (Symfony for web, Python+Go for
compute, R for science/exploration) — lives in
`~/Programming/RStudioProjects/DNCA/DNCA_Proposals.md`. A condensed PoC plan is in
this repo: `PoC_PLAN.md`.

**Topic 6 (Deforestation, Hansen Global Forest Change) was chosen as the first
PoC** because its data is free, global, and needs no fieldwork. It is now working
end-to-end (see §3). The dashboard sidebar already stubs the future topics:
Settlements, Drainage, Invasives, Wildlife.

**The ecosystem principle:** own the stack, minimal third-party deps, everything
under the `fundistadi` GitHub org where reusable. **DNCA/Ngorongoro must never be
mentioned in the public `fundistadi/*` repos** (this repo is private — fine here).

## 2. The fundistadi ecosystem built around this PoC

| Repo | What | Status |
|---|---|---|
| `fundistadi/postgis-bundle` (public, Packagist **^0.2** — **renamed** from `fundistadi/fundi-postgis`; namespace `FundiStadi\PostGISBundle`) | In-house Doctrine DBAL 4 / ORM 3 PostGIS bundle: `geometry`/`multipolygon` types (GeoJSON in/out), auto `USING gist` indexes, churn-free typed-column introspection | Proven end-to-end in this app; dnca migrated to the new name (commit 78adb3d) |
| `fundistadi/fundi-cli` (private, Go, **v0.4.2** via `brew install fundistadi/tap/fundi`) | Docker-free dev supervisor: FrankenPHP + Mercure + workers + shared HTTPS proxy + native DB provisioning | This app runs under it |
| `fundistadi/postgis` (public, GHCR) | Own multi-arch PostGIS Docker image (postgres:17 + PGDG) — used by CI, not local dev | Published |
| Local paths | Bundles live in the `~/Programming/PhpstormProjects/fundistadi/` **workspace** (`postgis-bundle/`, `gdal-bundle/` — each its own repo; the workspace itself is a private repo tracking only shared `CLAUDE.md` + `.claude/skills`); `~/Programming/GoLandProjects/fundi-cli`; `~/Programming/DockerProjects/postgis` | — |

**fundi-cli database model** (shipped): no `database:` key in `.fundi.local.yaml`
→ fundi ensures the project DB on whatever local Postgres the app's `.env` names;
`database: postgis` (this repo's setting) → fundi runs its **own** native PostGIS
cluster, one per Postgres major, under `~/.fundi/postgres/<major>/`.

## 3. This app — current state (all working)

**Run:** `fundi server:start` (from a shell where `postgres --version` = 17) →
`https://dnca.localhost/map`. DB = fundi's PostGIS-17 cluster, port **5434**, db
`dnca` (DSN in `.env.local`, written by fundi). Workers: the Tailwind watcher
(declared in `.fundi.local.yaml`).

**Bounded contexts** (deptrac-enforced, `deptrac.yaml`):
- `App\Spatial` — shared spatial kernel (naming settled after Geo→Geospatial→
  **Spatial**, matching PostGIS/LongitudeOne vocabulary). Depends only on itself.
  - `Entity\AreaOfInterest` — holds the **real WDPA Ngorongoro boundary**
    (8,271 km², 57 pts, `source=WDPA`; file: `data/boundaries/ngorongoro-nca.geojson`,
    extracted from the WDPA `.gdb` in `~/Downloads` via ogr2ogr).
  - `Command\ImportAreaOfInterestCommand` — `app:aoi:import <name> <file.geojson>
    [--source]`, accepts Geometry/Feature/FeatureCollection → one MultiPolygon.
- `App\Forest` — deforestation topic (may depend on Spatial; Spatial never on topics).
  - `Entity\ForestLossYear` — **real Hansen GFC loss 2001–2023** (`source=hansen`,
    ~3,214 ha total; 2001 = 1,657 ha known Hansen baseline artifact). One dissolved,
    simplified MultiPolygon per year.
  - `Controller\MapController` — `/map` (dashboard page) and
    `/api/forest-loss.geojson`; also `yearColor()` (PHP copy of the JS year ramp —
    keep in sync).

**PostGIS layer:** migrations emit `geometry(MultiPolygon,4326)` + `USING gist`;
a second `doctrine:migrations:diff` reports "No changes" (the churn-free proof).

**Frontend** (Tailwind 4 via `symfonycasts/tailwind-bundle` — no Node; Stimulus +
AssetMapper; styles in `assets/styles/app.css`, never inline; icons are inline
Lucide SVGs, never emoji):
- `templates/layout.html.twig` — dashboard shell: dark-green sidebar (Forest loss
  active + 4 "Soon" topics), top bar with stat chips.
- `templates/forest/map.html.twig` — the map view: base toggle (satellite default,
  OSM alternate), **dual-handle year slider** (two stacked native range inputs +
  `range-thumb` utility in app.css), live range summary, **loss-by-year bar chart**
  (server-rendered, √-scaled, ramp-colored), legend, chips.
- `assets/controllers/map_controller.js` — **Leaflet 1.9.4** (self-hosted at
  `assets/leaflet/` incl. `images/`, classic `<script>` → `window.L`). Interactions:
  hover a bar → spotlight that year's polygons; click a bar → snap range to that
  year; year filter re-renders the GeoJSON layer; scale control bottom-right,
  zoom top-right.

**⚠ Map-library history (do not regress):** MapLibre was used first and REMOVED.
v6 is ESM-only and its separate web worker can't be wired under AssetMapper
without a bundler → **GeoJSON layers fail silently**; v5 UMD then went blank
(WebGL-dependent, undebuggable headlessly). Leaflet does raster tiles + GeoJSON in
plain DOM/SVG — no WebGL, no workers — and **renders in headless Chrome**, so UI
changes can be screenshot-verified. Revisit MapLibre only for vector tiles.
Related: `#map` carries Tailwind `isolate` so Leaflet's internal z-indexes (~400–
1000) don't cover the sibling control panel (`z-10`).

**Git:** initialized 2026-08-08, initial commit `f1dd53f` (94 files). **No remote
yet.** `.env.local`, `var/`, `vendor/`, `.idea/` ignored; boundary data +
self-hosted Leaflet committed.

## 4. Known constraints & pending decisions

- **`doctrine/orm` pinned to `3.6.7`** (see README "Notes"): orm 3.6.8's
  `setSchema()` requires the unreleased `doctrine/dbal ^4.5`, and Symfony's
  doctrine-bridge listeners call it whenever it exists → `migrations:diff` breaks
  on dbal 4.4. **Revert to `^3.6.8` when dbal 4.5 releases.**
- **phpstan:** no config in this repo yet; `--level max` shows 3 pre-existing
  false-positives (entity `$id` ×2 → needs `phpstan-doctrine`; `Kernel::
  getAllowedEnvs()`). All session-written code is max-clean. TODO: add
  phpstan.neon + phpstan-doctrine so max becomes a real gate.
- **Tailwind watcher**: if styles don't rebuild, its fundi circuit breaker tripped
  earlier — restart fundi. Manual build: `php bin/console tailwind:build`.
- Deferred: Flex recipe for fundi-postgis "when well tested"; pushing this repo to
  a private remote.

## 4b. Test suite (added 2026-08-08 — TDD is now enforced)

Mirrors vivutio: `tests/{Smoke,Unit,Integration,Functional,Panther}`, PHPUnit
suites `default` + `panther`, **DAMA** rollback + **Foundry** factories (factories
live in-context: `src/<Context>/Factory/`), Panther e2e in real Chrome on a
dedicated `dnca_test_panther` DB (`#[SkipDatabaseRollback]`, drop+migrate per
class). 21 tests green: unit (GeoJsonNormalizer, LossYearPalette — both extracted
from command/controller for testability), functional (/map page, forest-loss API),
integration (aoi:import against real PostGIS, asserts `GeometryType(geom)`),
e2e (Leaflet boots, boundary+loss SVG render, base toggle switches tiles).
Commands: `composer test` / `test:e2e` / `check`. Key wiring: `.env.test.local`
carries the DSN (Symfony skips `.env.local` in test env); first migration now
does `CREATE EXTENSION IF NOT EXISTS postgis`; chromedriver via `vendor/bin/bdi
detect drivers` (gitignored `drivers/`). Conventions encoded in
`.claude/skills/dnca-conventions/SKILL.md`.

Also noted by a parallel session: `doctrine:schema:validate` flags unmapped
`ogr_system_tables.*` (GDAL bookkeeping from the data imports) — do NOT let
Doctrine drop them; optionally hide via a doctrine `schema_filter` regex.

## 5. Ingestion — IMPLEMENTED in PHP (2026-08-08); the Python proposal below is SUPERSEDED

The Hansen ETL now lives in the app as the **`App\Ingestion` bounded context**
(deptrac: `Ingestion: [Ingestion, Spatial, Forest]`), built on
**`fundistadi/gdal-bundle` ^0.1** — no Python, no microservice:

- `Service\HansenTileService` (behind `Service\TileSourceInterface`) — bbox →
  granule /vsicurl URIs (unit-tested).
- `MessageHandler\IngestHansenLossHandler` — gdalwarp clip (nodata **255**) →
  **`gdal raster polygonize`** (GDAL 3.11+ C++ subcommand — this is why no
  raster2pgsql detour and no gdal_polygonize.py) → ogr2ogr into the
  `ingest_hansen_raw` staging table (`--config OGR_PG_ENABLE_METADATA NO` so
  ogr_system_tables never reappears) → transactional per-year dissolve into
  forest_loss_year → `Entity\DatasetRun` provenance row (params/report/error).
- `Command\IngestHansenCommand` — `app:ingest:hansen <aoi-id-or-name>
  [--gfc-version --source --simplify]`, sync (message unrouted).
- doctrine `schema_filter: '~^(?!ogr_|ingest_)~'` hides OGR bookkeeping and
  staging tables from migrations:diff / schema:validate — both fully clean.
- **Validated:** the real run reproduced the manual pipeline's numbers exactly
  (3,214 ha total; 2001: 1,657; 2013: 186; DatasetRun #1). Integration test runs
  the whole ETL network-free via a generated fixture granule + stubbed
  TileSourceInterface.

Python remains reserved for future *pixel-math* science work (zonal stats,
NDVI, ML) — not ETL. Historical context (original Python proposal):

Replace the manual GDAL run (which loaded the current data) with a repeatable,
AOI-parameterized CLI. **Proposed and awaiting 3 decisions** (user was about to
answer when this handover was requested):

- **Shape:** standalone project in `~/Programming/PyCharmProjects/` (Python goes
  there — never inside dnca; that was decided explicitly). Generic — AOI is a
  parameter, no Ngorongoro references — so eligible for the fundistadi org.
- **CLI:** `gfc-ingest --aoi area.geojson --dsn $DATABASE_URL [--gfc GFC-2023-v1.11]
  [--table forest_loss_year] [--source hansen] [--simplify 0.0003]`
- **Stages (each pure & pytest-tested):** ① Hansen 10°×10° tile math from AOI bbox
  → ② `rasterio` windowed read over `/vsicurl/` masked to AOI, **nodata=255
  hardcoded** → ③ in-memory polygonize per year value 1–23 → ④ shapely dissolve +
  topology-preserving simplify + geodesic ha via `pyproj.Geod` → ⑤ `psycopg` 3
  transactional replace (`DELETE WHERE source=…` + insert per-year MultiPolygons)
  → ⑥ per-year ha report to stdout.
- **Stack:** Python 3.12; deps only `rasterio, shapely, pyproj, psycopg[binary]`;
  stdlib argparse; pytest + ruff; `uv` proposed for env/lockfile.
- **The 3 open decisions:** ① name & public-vs-private (`fundistadi/gfc-ingest`?
  `fundi-gfc`?) ② `uv` ok? ③ library-with-thin-CLI (recommended — lets a future
  worker/scheduler call it) vs CLI-only.

**Why these stages are shaped this way — two data bugs already hit manually:**
`gdalwarp -dstnodata 0` silently bumps no-loss 0-pixels to 1 (=year 2001,
inflating it to the whole AOI); `gdal_polygonize` **appends** to an existing
output file (mixed runs). The pipeline design makes both impossible.

Reference numbers for validating a rerun (current DB, `source=hansen`): total
3,214 ha; 2001: 1657, 2010: 185, 2013: 186, 2017: 114, 2023: 7.

## 6. Environment gotchas (verified this session)

- Homebrew `postgresql@16` (5432) and `@17` (5433) both run; **only 17 has
  PostGIS**. `~/.zshrc` appends `postgresql@17/bin` to PATH so 17 is the default
  CLI; an inherited stale PATH may still show 16 in old shells.
- Port **5432 = pg16** and hosts **arifika's** DB (migrated off Docker this
  session — see `~/.claude` memory of the old project dir, or just know it's done
  and healthy). Port **54320** is vivutio-tdd's Docker Postgres — a past typo for
  5432 caused confusion once.
- Headless Chrome screenshots: use `--headless=new --disable-gpu`, a throwaway
  `--user-data-dir`, `--virtual-time-budget`, and **run it in background, poll for
  the PNG, then kill** — Chrome hangs on exit. Never `pkill "Google Chrome"` (kills
  the user's real browser). Leaflet pages render fully headless.
- GDAL CLI (`gdalwarp`, `gdal_polygonize.py`, `ogr2ogr`, `gdalinfo`) is installed
  via brew and works with `/vsicurl` Hansen reads.

## 7. Conventions

TDD (vivutio-style); deptrac layer per bounded context; PHP 8.4; Tailwind 4 via
tailwind-bundle; styles in app.css; Lucide inline SVGs, no emoji; commit messages
explain the why; `FundiStadi` two-word namespace, "PostGIS" all-caps; language →
IDE dir (PHP→PhpstormProjects, Go→GoLandProjects, Python→PyCharmProjects,
R→RStudioProjects).

---

## 8. DELTA 2026-08-08 → 09 — read this first when continuing

_Compiled at handover to PhpStorm, 2026-08-09. Commits `e1f4f95…d9aaac1`. The
durable decisions also live in Claude's auto-memory (files
`uhifadhi-{design-contract,business-model,instance-architecture,stack-decision}`),
but this section is self-sufficient._

### 8.1 App changes (all committed, all tests green — 36 default + 1 Panther)

- **Multi-area schema** (`e1f4f95`): `forest_loss_year.aoi_id` (NOT NULL,
  CASCADE), `dataset_run.aoi_id` (nullable, SET NULL). Hansen handler is
  **full-ORM** end-to-end: gdalwarp clip (nodata 255) → `gdal raster polygonize`
  → GeoJSON file → batched ORM persist into `HansenLossPolygon` staging entity →
  DQL dissolve (`ST_Union`+`ST_MakeValid`+`ST_SimplifyPreserveTopology`+
  `ST_CollectionExtract`+`ST_Multi`, area via `Geography()` cast) → per-(aoi,
  source) replace with native `remove()`. **No raw SQL anywhere in the app; no
  tool touches the DB** — the standing rule.
- **postgis-bundle 0.4 adopted** (`b4bd03f`): `AreaOfInterestRepository extends
  SpatialEntityRepository` (bundle discovers the geometry column from Doctrine
  metadata; throws `MissingSpatialColumnException` otherwise). App uses
  `stAreaKm2(['id' => …])`, `findStIntersecting()`. Naming law: DQL functions =
  verbatim PostGIS names; repo methods = `st` prefix + unit suffix — `st` marks
  "comes from the bundle, never Doctrine core". Extend-when-you-need-it: only
  spatial repos re-parent.
- **Dashboard bounded context** (`9e776c2`): deptrac
  `Dashboard: [Dashboard, Spatial, Forest, Ingestion]` — the ONLY layer that
  sees everything; nothing depends on it. `AreaController`: `/` areas index,
  `/areas/new` upload, `/areas/{id}` detail (Leaflet map + loss bars + runs),
  `/areas/{id}/ingest` POST (CSRF `'ingest'.$id`) dispatching `IngestHansenLoss`
  **async** (messenger doctrine transport; worker in `.fundi.local.yaml` with
  `memory_limit=1G`; CLI stamps `TransportNamesStamp(['sync'])`; in-memory in
  tests). `Forest\Controller\ForestLossApiController`:
  `/api/areas/{id}/forest-loss.geojson`.
- **Upload robustness** (`9730747`): a POST exceeding `post_max_size` arrives
  EMPTY → form never "submitted" → guard renders **422** + FormError naming the
  limit (Turbo requires 422-or-redirect). Root cause of the Gombe failure:
  FrankenPHP **embeds its own PHP and ignores system php.ini** → fundi
  **v0.4.3** now writes `php_ini upload_max_filesize/post_max_size 512M` into
  the generated Caddyfile.
- **Nested-archive boundary import** (`f31e256`): WDPA downloads are
  zips-of-zips with CSVs/PDFs; `BoundaryImportService.candidateSources()` probes
  `/vsizip/` of the typed upload, then extracts and walks for nested
  `.zip/.shp/.gpkg/.kml/.kmz/.gdb`, `probePolygonLayer()` prefers polygon
  layers, `toWgs84GeoJson()` reprojects. Extensionless PHP tmp uploads get their
  real extension restored (typed copy). BrowserKit drops the client filename →
  test helper appends the extension to `tempnam`. **Known accepted risk:** the
  scan is greedy first-polygon-wins; the agreed-but-unbuilt patch is
  "unambiguous scan": one dataset → import; multiple → refuse, list them, hint
  at WDPA chunks. (Verified fact: WDPA country `_shp_0/1/2` zips = ONE dataset
  feature-split into chunks — TZA 3×288 features, same schema, disjoint.)
- **Real data in DB right now:** aoi #2 Ngorongoro (WDPA, 8,271 km²) with the
  real 23-row Hansen series (3,214 ha; 2001:1657 artifact; 2013:186); aoi #3
  Gombe (56 km², imported via service probe — **user still wants to run the
  upload + ingest through the UI buttons himself**; NCA re-ingest through the
  button also still his to do).

### 8.2 The designs/ template app (`d9aaac1`) — THE implementation contract

`designs/` = 22 linked static HTML pages (open `designs/index.html`; ☀/☾ toggle),
regenerated by `python3 designs/_build/build.py` (data.py = case-study dataset —
NCA numbers REAL; charts.py/weather.py/fires.py/eco.py/extra.py = ~95 hand-drawn
SVG chart idioms; tiles/ = real Esri/GFW/OSM tiles). It encodes, after many
user-driven iterations:

- **Identity**: "survey plates" (index-tab cards, plate numbers, coordinate
  stamps) on a **forest/fire/water** token palette — green-tinted night canvas +
  bone-paper light theme, one `--*` token set flipped by `html.light`; jade
  accent ONLY for live/CTA; the yellow→red fire ramp is data-only. Calm: no
  decorative rings/glows (long-session comfort), no marks that look like
  encodings (KPI scale-bars were removed for this).
- **IA**: national level (Overview · Areas · **Alerts** · Compare · Runs ·
  Gallery) + **one sub-app per park** (hub + module tabs, grouped
  flux/pressure/pulse). Ngorongoro: 11 tabs (Forest, Climate, Drought,
  Vegetation, Stations, Land cover, **Anthropogenic**, **Tourism** (separate!),
  Livestock, Statistics). Serengeti: Fire management, Biodiversity/migration,
  Air & smoke. **Alerts is cross-cutting**: every module ends in "from proxy to
  alert" rule cards; alerts deep-link back into modules.
- **Maps**: geolocated data ALWAYS on real satellite imagery (never blank
  country outlines — uhifadhi is not Tanzania-only; basemaps auto-fit the
  tenant's areas); satellite⇄OSM toggle on expandable maps; areas register =
  dense table with real satellite thumbnails + filters, NOT a map.
- **Charts**: gallery.html names every idiom + source library + when-to-use —
  the product thesis ("scientists know the aids, programmers can build; bridge
  it"). `_build/charts.py` etc. are the porting spec for Twig components.

### 8.3 Strategy decisions (2026-08-09, user-confirmed)

- **Business model — AGPL open-core, Grafana-style**: app core AGPL-3.0 with ALL
  analytical modules open (breadth = moat + grant story; sell operations, never
  science); bundles stay MIT. `uhifadhi.org` = project/community/labs instance;
  `uhifadhi.com` = managed hosting (PRIMARY revenue; fundi = COGS advantage) +
  setup/support/training + later operational add-ons (SSO, SMS/WhatsApp alert
  delivery, white-label reports, federation). Buyers: authorities (TANAPA,
  NCAA), landscape NGOs, donor projects; WMAs via consortium/NGO. Housekeeping:
  DCO/CLA on contributions (keep dual-licensing power), register the
  **uhifadhi trademark**. Position as analytical observatory; integrate
  SMART/EarthRanger as sources, don't compete. Full AGPL explainer is in the
  auto-memory business-model file.
- **Instance architecture — instance-per-org, mode = data not code**: same app
  for labs (everything) / TANAPA (22 parks) / WMA consortium / single park; the
  difference is which areas are in the DB + `Platform` config. DB-per-instance,
  shared-nothing. **Seven hard anti-tenancy rules** (auto-memory
  `uhifadhi-instance-architecture`): no Organization entity / no tenant_id ever;
  AreaOfInterest stays the data root (groupings = tags, presentation only);
  repos never take owner filters; roles-within-instance only; cross-instance
  ONLY via a future Platform read API as HTTP client; seed profiles
  (nca/tanapa/labs) prove mode==data in CI; arch test greps for tenant-shaped
  columns. Hierarchy: phase 1 needs NO federation (public datasets are
  reproducible — a parent instance just ingests all its boundaries itself);
  phase 2 federates only locally-collected data (stations/patrols) via
  pull-based remote sources.
- **Contexts to come**: `Platform/` (instance identity/config, seen by all,
  sees none), one context per module mirroring `Forest/`, `Alerts/` owning the
  cross-module contract (modules dispatch `AlertRaised` via Messenger; deptrac:
  module → [itself, Spatial, Ingestion, Alerts\Message]). Module activation =
  config array → drives the sub-nav.
- **Stack — Symfony confirmed over Django**: viz is language-neutral (proven by
  designs/), small-n stats live in SQL first + pure PHP second, raster science
  is GDAL/ETL not scipy, heavy future science = async Messenger workers;
  FrankenPHP+Mercure+Turbo/Live Components+fundi are load-bearing for the
  hosting business. **Planned bundles (extract, don't pre-build — promote when
  a third consumer exists)**: `fundistadi/plot-bundle` (Symfony-UX-style Twig
  components, one per chart idiom, themed server-rendered SVG, MIT — chart
  grammar is the commons, the survey-plate skin stays in the AGPL app) and
  `fundistadi/stat-bundle` (pure-PHP OLS+CI/LOESS/KDE/quantiles/ECDF/PCA,
  tested against R/scipy fixture outputs).

### 8.4 Ecosystem versions (current)

postgis-bundle **v0.4.0** (Packagist, pills verified) · gdal-bundle **v0.1.0**
(+2 commits) · fundi **v0.4.3** (php_ini upload limits in Caddyfile) ·
release-checklist skill in the fundistadi workspace covers tag→GH release→
Packagist→badge verification.

### 8.5 Next steps, in order

1. **Implement the design in the app** (the reason for this handover): port
   tokens + `Plate` Twig component from `designs/uhifadhi.css` / the plate
   markup; re-skin `layout.html.twig` + `dashboard/{index,new,show}.html.twig`
   against `designs/{areas,new-area,area-ngorongoro}.html`; charts as app-internal
   Twig SVG components (port from `_build/charts.py`); screenshot-verify both
   themes (headless Chrome tips in §6). TDD; category self-audit before commits
   (`.claude/skills/dnca-conventions`).
2. **Unambiguous-scan patch** for `BoundaryImportService` (teaching error on
   multi-dataset archives) — agreed, unbuilt, guards real uploads.
3. **Interactive ingestion jobs** milestone: async upload as a watched job,
   `awaiting_input` status + question/answer (the WDPA-chunks "combine?" case),
   `mercure: true` in fundi, live status replacing the 6s meta-refresh —
   designs/ingestion.html + new-area.html specify the UX.
4. User's own button runs: Gombe upload+ingest via UI; NCA re-ingest validating
   3,214 ha / 2001:1657 / 2013:186.
5. Backlog: auth (task #19, deferred); phpstan.neon + phpstan-doctrine; Flex
   recipe for postgis-bundle "when well tested"; push repo to a private remote;
   OSM attribution when the basemap toggle ships.
