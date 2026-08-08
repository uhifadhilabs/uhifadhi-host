# dnca — Project Handover & State

_Last updated: 2026-08-08. Self-contained: written for a fresh Claude Code session
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
| Local paths | `~/Programming/PhpstormProjects/fundi-postgis`, `~/Programming/GoLandProjects/fundi-cli`, `~/Programming/DockerProjects/postgis` | — |

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

## 5. NEXT UP — Python Hansen ingestion pipeline (proposal on the table)

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
