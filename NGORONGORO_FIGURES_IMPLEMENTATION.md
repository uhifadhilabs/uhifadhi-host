# Ngorongoro six-objectives analysis → uhifadhi implementation handover

_Written 2026-08-12 by the R-analysis session for the uhifadhi agent. Read this
alongside `NGORONGORO_MODULE_DATA_MAP.md` (module taxonomy) and `HANDOVER.md`
(app state). The auth feature in flight is untouched by this document — it is
docs only, no code._

## 1. What was analysed, and where it lives

A single reproducible R script — `~/Programming/RStudioProjects/ncaa/ncaa_analysis.R`
(git-committed) — analyses all six NCA research objectives on open, no-auth data
and renders a 17-figure presentation deck
(`output/ngorongoro_research_objectives.pdf`). Per objective:

| Obj | Analysis | Data | Method |
|---|---|---|---|
| 1 Settlement | Built-up trend 1975–2020 + growth map + land cover + fragmentation | GHSL GHS-BUILT-S (1 km, 5 snapshot years), ESA WorldCover 10 m | zonal sums inside NCA vs 10 km ring; linear trend; `landscapemetrics` patch metrics |
| 2 Vegetation | Peak-greenness map, phenology curve, Rao's Q diversity proxy | MODIS MOD13Q1 NDVI (38 dates, 2022) via Planetary Computer STAC | per-pixel annual max; per-date spatial median/10–90 pct; `rasterdiv` Rao's Q on peak NDVI |
| 3 Wildlife | Elephant habitat suitability + Lantana invasion risk + driver ranking | GBIF sightings; WorldClim bio1/4/12/15, elevation, slope, distance-to-water (JRC GSW) | Maxent (`maxnet`), 70/30 split, AUC + omission; permutation importance |
| 4 Forest | Biomass map, canopy-height map, proxy agreement | ESA CCI Biomass 2020 (100 m), Potapov/GLAD height 2019 | cell-wise comparison, r = 0.71 (both proxies positive only — zero-AGB tall-canopy cells are water/no-data artefacts) |
| 5 Roads | Network map, length by class, remoteness raster | OpenStreetMap (Overpass, cached GeoPackage) | `st_intersection` with NCA, distance-to-road raster at 100 m |
| 6 Synthesis | One-line indicator per objective + provenance/uncertainty table | all of the above | scorecard + CSVs |

Every figure carries a caption block: **Figure N | Title** → *subtitle* →
**Answers (Objective N)** → **Takeaway** (computed numbers) → **Limitations** →
**Next** (the upgrade path — which is exactly what uhifadhi should implement).

**The app never touches R or its artefacts.** The R project is the prototype
and the specification: it proves each pipeline works on open data and documents
the exact source URLs, CRS quirks and processing recipe (read the script's
comments for each Q section — they are the ingestion spec). uhifadhi
re-implements each pipeline natively from the PRIMARY sources (GHSL zips,
WorldCover S3 COGs, MODIS via Planetary Computer STAC, GBIF API, CEDA, GLAD,
Overpass) — exactly as the Forest module already does with Hansen. The R
outputs (`ncaa/output/*.csv`) serve one purpose in the app repo: **acceptance
fixtures** — when the app's own pipeline computes built-up 2020 for the NCA, it
should land on ~3.90 km²; road length ~2,347 km; canopy↔biomass r ≈ 0.71. Bake
those into ingestion tests as tolerance assertions.

## 2. Licence context: the core is **AGPL-3.0**

Per `uhifadhi-ops/BUSINESS.md` (the `"proprietary"` in composer.json is a stale
placeholder — worth fixing while you're in there).

AGPL composes freely with MIT/BSD/Apache/ISC dependencies — the *product*
carries AGPL, permissive deps are unaffected. So the constraint is NOT
"avoid copyleft"; it is: **never embed libraries whose terms are incompatible
with AGPL distribution**:

- **Forbidden:** Highcharts (CC BY-NC / paid), amCharts (paid/attribution
  tiers), FusionCharts (paid), **Mapbox GL JS v2+** (proprietary — MapLibre is
  the BSD fork), Kendo/Syncfusion. None of these can ship inside an AGPL app.
- **Fine if ever needed:** Chart.js (MIT), Plotly (MIT), D3 (ISC),
  MapLibre (BSD-3), OSRM (BSD-2), titiler (MIT). Leaflet (BSD-2) is already
  vendored and stays THE map layer.

## 3. How charts are rendered TODAY — respect the existing idiom

The app already has a charting approach and it is NOT a JS chart library:
**server-rendered SVG built in Twig**, ported from the design system's
`designs/_build/charts.py`:

- `templates/_sparkline.html.twig` — pure-Twig polyline sparkline (idiom PL·31).
- `templates/forest/_annual_loss_chart.html.twig` — full bar chart with
  gridlines, mean reference line, clipped-artifact annotation, and — the key
  trick — **Stimulus actions directly on the SVG `<rect>`s**
  (`click->map#focusYear`, `mouseenter->map#highlightYear`) so the chart drives
  the map with zero chart-library code.

**Verdict: keep this idiom. Do NOT introduce Chart.js.** Reasons: (a) it
already solves chart↔map interactivity more directly than a canvas library
could (SVG elements are addressable; Chart.js canvas pixels are not); (b) it
re-renders for free under LiveComponent/Turbo — no JS lifecycle to manage,
no `destroy()`/reinit dance; (c) zero new deps matches the ecosystem principle;
(d) full control of the design language.

Three real problems to fix while extending it (observed in the current code):

1. **Duplication drift.** Each Twig chart is a hand-"faithful port" of a
   `charts.py` function — two implementations of every chart that will drift.
   Extract shared Twig partials/macros for the primitives (axis+gridlines,
   linear scale, bar row, line+band) so new figures compose them instead of
   re-porting geometry per chart.
2. **Scale math re-implemented per template.** `ymax = ceil(peak/50)*50` style
   logic inline in Twig will breed off-by-one axis bugs. Move scale/tick
   computation into a small PHP helper (a `ChartScale` value object the
   controller passes in) and keep Twig to pure geometry.
3. **Point-count discipline.** Inline SVG is fine to a few thousand nodes. The
   Q4 scatter has 240k cells — decimate server-side (≤3–5k points) before it
   ever reaches Twig; same for any dense time series.

## 4. The implementation pattern (same for all 17 figures)

1. **Data in PostGIS, not in the browser — ingested from the primary sources.**
   Each figure's Data line names its source; the R script documents the exact
   URLs and recipe. Ingestion-module runs download → clip to the area boundary →
   write zonal stats to versioned tables and rasters to either XYZ tiles or,
   simplest first pass, a web-mercator PNG + bounds JSON served as a Leaflet
   `ImageOverlay`. No R anywhere in the pipeline.
2. **A LiveComponent per figure panel** owns the state (year range, species,
   layer toggles) and re-renders the Twig — which re-renders the SVG chart —
   on change. No chart JS to coordinate.
3. **Stimulus for behaviour only**: the existing `map_controller` plus small
   controllers for chart↔map linking (already the idiom) and raster overlays
   (sketch below).
4. **Heavy computation = CLI binaries, orchestrated by PHP** — the pattern the
   app already uses (GDAL shelled out for boundary ingestion). A Messenger job
   invokes the binary (Symfony Process), the binary writes results back to
   PostGIS/files, the LiveComponent gets a Turbo Stream on completion.
   - **GDAL covers most of it**: `gdalwarp` (clip/reproject, incl. the
     Mollweide-CRS assertion GHSL needs), `gdaldem` (hillshade),
     `gdal_proximity` (distance-to-road / distance-to-water),
     `gdal_calc`/`gdal_polygonize`, `ogr2ogr` (Overpass/GPKG → PostGIS).
   - **Custom Go binaries** for what GDAL can't do, when needed: moving-window
     Rao's Q, patch/fragmentation metrics (connected-component labelling over
     the land-cover raster), zonal time-series extraction at scale, and
     eventually the Maxent-style SDM fit (penalised logistic regression — small,
     well-specified, and Go is a good fit). One static binary per job, versioned
     with the app, invoked exactly like GDAL. Keep the R prototype's outputs as
     the correctness oracle for each Go port.

```js
// assets/controllers/raster_layer_controller.js (adds a clipped-raster overlay
// + optional time slider to the existing Leaflet map)
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { frames: Array, bounds: Array, opacity: Number };
  connect() {
    this.map = this.element.closest('[data-controller~="map"]').map; // existing map_controller exposes it
    this.layer = L.imageOverlay(this.framesValue[0].url, this.boundsValue,
                                { opacity: this.opacityValue ?? .8 }).addTo(this.map);
  }
  seek(e) { this.layer.setUrl(this.framesValue[e.target.value].url); } // time slider input
  disconnect() { this.layer?.remove(); }
}
```

## 5. Figure-by-figure: module, viz, and what the "Next" action needs

Modules follow the taxonomy in `NGORONGORO_MODULE_DATA_MAP.md` (flux/pressure/
biodiversity). Where a bounded context doesn't exist yet in `src/`, it needs
creating (marked *new*).

| Fig | Figure (file) | Module (bounded context) | App viz | "Next" action → what to build |
|---|---|---|---|---|
| 1 | Settlement growth map (`q1_builtup_map`) | **Settlement** *(new — "Anthropogenic" in the taxonomy)* | Leaflet: hillshade base + categorical overlay + landmark markers | Annual 10 m settlement layers (WSF/Dynamic World ingestion job); ward/village boundary layer; click → LiveComponent drill-down panel with per-village trend |
| 2 | Built-up trend (`q1_builtup_trend`) | Settlement | SVG chart partial: two-series line (extend the sparkline idiom) | Yearly scheduled ingestion (Symfony Scheduler + Messenger); alert rule entity ("Δbuilt-up > x% in ward y") + notification channel |
| 3 | Land cover map (`q1_landcover_map`) | **LandCover** *(new)* | Leaflet categorical overlay + legend toggles (LiveComponent state) | Yearly WorldCover/Dynamic World versions; year-vs-year change map (server-side diff), rendered as a third overlay |
| 4 | Fragmentation (`q1_fragmentation`) | LandCover | SVG chart partial: horizontal bars (annual-loss idiom, rotated) | Recompute patch metrics per land-cover version → Go metrics binary via Messenger; store per-version metrics table; small multiples over time |
| 5 | Peak NDVI map (`q2_ndvi_map`) | **Vegetation** *(new)* | Leaflet continuous overlay + season selector | Sentinel-2 seasonal composites via STAC ingestion; COG tiling (titiler) once ImageOverlay outgrows itself |
| 6 | Phenology (`q2_phenology`) | Vegetation | SVG chart partial: line + shaded band (polyline + polygon) | Per-16-day zonal stats table (multi-year); anomaly = current vs baseline percentile band; drought alert when below p10 for k periods |
| 7 | Rao's Q diversity (`q2_raoq_diversity`) | Vegetation | Leaflet continuous overlay | Field richness-plot CRUD (LiveComponent form, lat/lon picker on the map); Go Rao-window binary re-derives the proxy when new imagery lands |
| 8 | Elephant suitability (`q3_elephant_suitability`) | **Wildlife** *(new)* | Leaflet overlay + sightings point layer toggle | Sightings ingestion: ranger/census entry form (mobile-friendly LiveComponent) + GBIF sync job; "re-fit model" Messenger job with AUC surfaced in UI |
| 9 | Elephant drivers (`q3_elephant_var_importance`) | Wildlife | SVG chart partial: horizontal bars (annual-loss idiom, rotated) | Spatial cross-validation inside the SDM binary; show CV-AUC beside train AUC |
| 10 | Lantana risk (`q3_lantana_suitability`) | Wildlife (invasives view) | as fig 8, warm palette | Invasive-sighting reports with photo upload; risk-zone watchlist (polygons where risk > threshold) driving patrol tasking |
| 11 | Lantana drivers (`q3_lantana_var_importance`) | Wildlife | as fig 9 | as fig 9 |
| 12 | Biomass map (`q4_biomass_map`) | **ForestStructure** *(new — Forest module exists for forest LOSS; keep loss and structure separate per the taxonomy)* | Leaflet continuous overlay | GEDI L4A ingestion (needs NASA Earthdata credentials — first credentialed source; store secrets via env/vault); footprint table + calibration view |
| 13 | Canopy height (`q4_canopyheight_map`) | ForestStructure | Leaflet continuous overlay | Drone-LiDAR upload path (large-file ingestion) when acquisition happens |
| 14 | Proxy agreement (`q4_proxy_agreement`) | ForestStructure | SVG scatter — decimate server-side (≤3-5k points), never ship 240k rows as DOM nodes | Recompute agreement against GEDI footprints as reference once fig 12's ingestion exists |
| 15 | Remoteness map (`q5_roads_access_map`) | **Roads** *(new)* | Leaflet: distance raster + roads GeoJSON from PostGIS | Self-hosted OSRM (BSD-2) → drive-time isochrones endpoint; road-condition report entity (form + map picker) rendering condition badges on segments |
| 16 | Road network (`q5_roads_map`) | Roads | Leaflet vector styled by `highway` class | OSM diff sync job; merge field GPS traces (GPX upload) flagged for review |
| 17 | Synthesis scorecard (`q6_synthesis`) | **Dashboard** (exists) / Statistics | LiveComponent stat tiles reading an `indicator` table | The `indicator` table IS the deliverable: latest value + provenance + computed-at per metric, written by every ingestion/compute job; tiles link to the owning module page |

Cross-cutting: captions' **Answers/Takeaway/Limitations** text should travel with
each figure into the app as a description panel — it is already written and
audience-tested; store it with the figure config, don't rewrite it.

## 6. On mocks

Interactive JS mocks were deliberately NOT added to `designs/` in this session —
the auth agent owns the working tree and `designs/_build` has its own pipeline I
did not want to collide with. The pattern in §4 plus the per-figure
table are intended to be sufficient. If a mock is wanted first, start with
fig 1 + fig 2 as the exemplar pair (map + linked chart, one LiveComponent):
they exercise every pattern the other 15 figures need.

## 7. Gotchas the analysis already paid for (do not rediscover)

- MOD13Q1 NDVI arrives at ×10⁴ or ×10⁸ integer scaling depending on the GDAL
  path — normalise before use (see `scale_ndvi()` in the R script).
- GHSL rasters are World Mollweide (`ESRI:54009`) and lie about it — assert the
  CRS on ingest.
- Potapov canopy height is integer metres with 101/102 water/no-data codes;
  drop >60 m and require biomass > 0 when comparing.
- GBIF sightings are road/tourism-biased — surface AUC as "optimistic" in the
  UI until census data replaces them (the caption text already says this).
- WorldCover classifier ≈76% accurate — never present class areas without that
  caveat attached.
