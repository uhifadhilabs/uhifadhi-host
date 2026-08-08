# DNCA PoC — Deforestation (Topic 6): structure & build plan

**Project:** `dnca` (Symfony 8.1, PHP 8.4 target)
**PoC topic:** Topic 6 — Deforestation, using the Hansen Global Forest Change dataset.
**Goal:** prove the **domain-isolated modular-monolith architecture** (vivutio-style) end to end — ingest a real spatial dataset → PostGIS → API Platform → interactive map with year slider → export — so every future topic drops in as an isolated module.

> **Status: STRUCTURE ONLY.** The project, module layout, deptrac rules, and Doctrine
> mapping are in place and verified (deptrac: 0 violations; app boots). **No feature code
> yet** — this document lists what the build phase will add, pending your go-ahead.

---

## 1. What exists now (verified)

- Symfony **webapp** skeleton, dependency resolution **pinned to PHP 8.4** (`config.platform.php = 8.4.13`, `.php-version`) to match vivutio's `>=8.4` target while running on the BC 8.5 runtime.
- **Modular `src/`** (flat `Controller/Entity/Repository` removed — everything lives in a module):
  ```
  src/
    Geo/                     # shared spatial kernel (marker: Geo.php)
      Doctrine/ Entity/ Repository/ Service/ Domain/ Twig/
    Forest/                  # Topic 6 domain (marker: Forest.php)
      Entity/ Repository/ Service/ Domain/ Controller/ ApiResource/ LiveComponent/
  ```
- **deptrac** (`deptrac/deptrac ^4.7`) with enforced isolation — `deptrac.yaml`:
  - `Geo: [Geo]` — kernel depends only on itself
  - `Forest: [Forest, Geo]` — topic may use the kernel only; nothing else may reach into it
- **Doctrine** mapping is per-module (`src/Geo/Entity` → `App\Geo\Entity`, `src/Forest/Entity` → `App\Forest\Entity`); `auto_mapping` off. Skeleton already targets **PostgreSQL**.

Run the guardrail any time: `php vendor/bin/deptrac analyse`

---

## 2. Architecture (target)

```
   WEB TIER (this app)                         COMPUTE TIER (offline, separate)
   Symfony + API Platform                      Python: clip Hansen GFC to the NCA
   + Doctrine (+ PostGIS spatial types)          AOI, polygonize annual loss,
   + Symfony UX (LiveComponent/Turbo/Stimulus)   load into PostGIS / write COG
   + MapLibre viewer + export                          │
             │                                         │
             └──────────────── PostGIS ────────────────┘
                          (single source of truth)
```

The web app only ever reads/serves what is in PostGIS. The Hansen clip is a one-off
Python step for the PoC (no Go needed at this scale).

---

## 3. Build phase — what happens on your go-ahead

### 3.1 Packages to add
- `api-platform/symfony` — REST + GraphQL + OpenAPI for the domain resources.
- **No spatial dependency** — PostGIS support is our **in-house `Geo` package** (see §5). Custom ~40-line Doctrine geometry type + hand-written spatial DDL in migrations + a `schema_filter`. Grows with the project instead of coupling us to a third-party abstraction.
- `symfony/ux-live-component`, `symfony/ux-twig-component` — the map + year-slider component.
- `symfonycasts/tailwind-bundle` — styling parity with vivutio (optional).
- (later, cross-topic) `symfony/mercure-bundle` — live push; not needed for the PoC.

### 3.2 Geo kernel (shared)
- `Doctrine/` — register PostGIS geometry types; enable the `postgis` extension via migration.
- `Entity/AreaOfInterest` — the NCA boundary + buffer polygon (one row for the PoC).
- `Entity/RasterAsset` — reference to a COG (uri + epoch + kind) for optional raster overlays.
- `Service/ExportService` — serialize a query result to **GeoJSON / CSV / GeoPackage**.
- `Twig/` + `assets/` — a MapLibre base-map Twig component + Stimulus controller.

### 3.3 Forest domain (Topic 6)
- `Entity/ForestLossYear` — `id`, `year:int`, `geom:MultiPolygon(4326)`, `areaHa:float`, `source:string`.
- `Repository/ForestLossYearRepository` — queries by year range / AOI.
- `Domain/` — small value objects (year range, loss summary).
- `Service/ForestLossImporter` — load the clipped Hansen GeoJSON into PostGIS.
- `ApiResource/` — API Platform resource over `ForestLossYear`: filters (year, bbox), pagination, export formats.
- `LiveComponent/ForestLossMap` — MapLibre map with a **year slider**, layer toggle, click-for-attributes, and an **export** button.
- `Controller/` — public map page + export routes.

### 3.4 Data pipeline (offline Python, one-off for PoC)
1. Define the **NCA AOI** polygon (load into `AreaOfInterest`).
2. Pull **Hansen GFC** `lossyear` (via GEE or the tiled downloads), clip to the AOI.
3. Polygonize per loss-year → GeoJSON (one feature set per year, with `areaHa`).
4. Load into PostGIS through `ForestLossImporter` (or `ogr2ogr` for the PoC).

### 3.5 Acceptance for the PoC
- Map loads NCA extent; **year slider 2001→latest** filters visible forest-loss polygons.
- Click a polygon → attributes (year, area). 
- **Export** the current selection as GeoJSON/CSV/GeoPackage.
- `deptrac analyse` stays green (Forest touches only Geo).
- Adding a second topic later = new `src/<Topic>/` module + one deptrac line `<Topic>: [<Topic>, Geo]` — no change to Forest or Geo.

---

## 4. Decisions (resolved)
- **Database:** skeleton's Docker Compose PostgreSQL + **PostGIS** extension. ✅
- **Spatial handling:** **in-house `Geo` package** — no third-party spatial library (see §5). ✅
- **AOI source:** **derived in Python** from **WDPA** (authoritative; OSM `geocode_to_gdf` fallback), reprojected to EPSG:4326, with a 5 km buffer ring. ✅
- **Tailwind styling:** _still open_ — Tailwind (vivutio parity) vs plain markup for the PoC.

## 5. In-house `Geo` package — design & growth roadmap

The `Geo` module is a real internal library, not glue. It owns all cross-cutting spatial
capability so no topic re-implements it, and it **accretes** as topics need more. Could
later be extracted to a private Composer package, but lives in `src/Geo/` for now.

**PoC scope (built now):**
- `Doctrine/Type/GeometryType` — ~40-line custom type; column as GeoJSON via `ST_AsGeoJSON` / `ST_GeomFromGeoJSON` (transparent read/write).
- `Doctrine/Migration/PostgisTrait` — reusable migration helpers: `enablePostgis()`, `addGeometryColumn()`, `addGistIndex()` — so every spatial migration is one-liners, not raw SQL each time. **This is the main "grows with the project" surface.**
- `Entity/AreaOfInterest` — `geom`, `zoneType` (`nca` | `buffer`); the reusable NCA extent.
- `Service/ExportService` — query result → GeoJSON / CSV / GeoPackage.
- `Twig/` + `assets/` — MapLibre base-map Twig component + Stimulus controller.
- `config` — `dbal.schema_filter` to ignore PostGIS system tables (`spatial_ref_sys`, …).

**Grows later (as topics demand):**
- More geometry types (Point/LineString/raster), SRID/geography variants.
- Registered DQL functions (`ST_Intersects`, `ST_MakeEnvelope`, `ST_Area`…) for viewport/bbox filtering.
- `Entity/RasterAsset` + COG/STAC helpers when raster overlays are needed.
- Tiling helpers / vector-tile endpoint if data volume outgrows GeoJSON.
- Shared spatial repository base (bbox filter, GeoJSON projection).

Every DNCA topic module depends on `Geo` (deptrac: `<Topic>: [<Topic>, Geo]`) and nothing else — so the package is the single place spatial concerns evolve.
