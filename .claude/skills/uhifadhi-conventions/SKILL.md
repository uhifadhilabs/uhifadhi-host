---
name: uhifadhi-conventions
description: >
  uhifadhi project specifics — READ (with the code-conventions skill) BEFORE writing
  or changing ANY code here. The general engineering discipline (outside-in TDD, the
  test pyramid, bounded contexts, thin models / lean controllers / logic in services,
  the class-category self-audit) lives in the `code-conventions` skill; this file
  keeps ONLY what is specific to uhifadhi: its bounded contexts, the PostGIS geometry
  workflow, UUID addressing, the test databases, and the frontend stack.
---

# uhifadhi conventions (project specifics)

**General discipline is in the `code-conventions` skill** (outside-in TDD, contexts,
thin models / lean controllers / services, the class-category audit). Also read
`CLAUDE.md` and `HANDOVER.md`. For UI work: `ui-design` then `design-to-code`.

## Bounded contexts (this project's layers)
deptrac is the law (`deptrac.yaml`):
- `App\Foundation` — cross-cutting entity concerns (UuidTrait, TimestampableTrait);
  seen by all, depends on nothing.
- `App\Spatial` — the shared spatial kernel; may use Foundation only.
- `App\Forest`, `App\Ingestion`, … — topic contexts; depend on `[self, Spatial,
  Foundation]` (Ingestion also on Forest), never on each other.
- `App\Dashboard` — the UI composition layer; may see everything; nothing depends on it.

## Storage / PostGIS
- Geometry columns via `fundistadi/postgis-bundle` (`FundiStadi\PostGISBundle`):
  `#[ORM\Column(type: 'multipolygon')]`; values are **GeoJSON strings**; migrations
  auto-emit `geometry(MultiPolygon,4326)` + `USING gist`. After a schema change run
  `doctrine:migrations:diff` **twice** — the second must say "No changes" (churn = a
  bundle bug, report it). Spatial repos extend the bundle's `SpatialEntityRepository`;
  DQL functions use verbatim PostGIS names, repo methods use the `st` prefix.
- **Public addressing is by UUID, never the sequential id.** Entities use
  `App\Foundation\Entity\Trait\UuidTrait` (+ `#[ORM\HasLifecycleCallbacks]`); routes
  are `{uuid}` with `Requirement::UUID` + `#[MapEntity(mapping: ['uuid' => 'uuid'])]`
  — so `/areas/2` 404s. Migrations that add a UUID column add-nullable → backfill
  (`gen_random_uuid()`) → set NOT NULL + unique.

## Tests (this project's wiring)
- Suites in `phpunit.dist.xml`: `default` (excludes `tests/Panther`) and `panther`.
  Commands: `composer test` · `composer test:e2e` · `composer check` (deptrac +
  twig/container lint + tests). All green before every commit.
- DAMA rolls back the default suite on `uhifadhi_test`; the dev DB `uhifadhi` (real
  Hansen/WDPA data) is never touched. Panther classes extend `tests/Panther/
  E2ETestCase`, carry `#[SkipDatabaseRollback]`, run on `uhifadhi_test_panther`
  (dropped + remigrated per class). Test DSN in `.env.test.local` (gitignored); the
  first migration `CREATE EXTENSION IF NOT EXISTS postgis`. Foundry factories in
  `src/<Context>/Factory/`.

## Frontend
- Tailwind 4 (tailwind-bundle): styles in `assets/styles/app.css` only — never inline
  `<style>`; rebuild with `php bin/console tailwind:build` if the fundi watcher isn't
  running. The survey-plate design system (channel tokens → `@theme`, light default /
  `.dark` opt-in) lives there.
- Map = **self-hosted Leaflet** (`assets/leaflet/`, `window.L`). Do NOT reintroduce
  MapLibre (silent worker/WebGL failures under AssetMapper). Keep the JS year-ramp
  stops identical to `LossYearPaletteService` (guarded by its unit test).
- Icons: **Symfony UX Icons** — `{{ ux_icon('lucide:map') }}` (lucide vendored in
  `assets/icons/`), never inline SVGs or emoji. Stimulus controllers in
  `assets/controllers/`.