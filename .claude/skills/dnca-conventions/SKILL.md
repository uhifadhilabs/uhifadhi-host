---
name: dnca-conventions
description: >
  Binding dnca conventions — READ BEFORE writing or changing ANY code in this project.
  Trigger for every task that creates/edits entities, controllers, services, commands,
  templates, Stimulus controllers, migrations, or tests. Covers: outside-in TDD (tests
  FIRST, always — this project is TDD by decree), the test pyramid and where each test
  goes, bounded contexts enforced by deptrac (Spatial kernel, Forest + topic siblings),
  thin entities / lean controllers / logic in services, the PostGIS geometry column
  workflow, and the frontend rules (Leaflet not MapLibre, Tailwind in app.css, Lucide
  SVGs not emoji).
---

# dnca conventions

Also read the auto-loaded `CLAUDE.md` and `HANDOVER.md` (project state).

## TDD is non-negotiable

**Write the test first, watch it fail, then implement.** Outside-in:

1. New page/endpoint/journey → **Functional** test (`tests/Functional/<Context>/`,
   `WebTestCase` + Foundry factories) or **Panther e2e** for browser behaviour.
2. New service/pure logic → **Unit** test first (`tests/Unit/<Context>/`, plain
   `TestCase`, no kernel). If logic is trapped in a controller/command, EXTRACT a
   service so it's unit-testable (see `GeoJsonNormalizer`, `LossYearPalette`).
3. Anything touching PostGIS semantics (geometry round-trips, SRID, GiST) →
   **Integration** test (`tests/Integration/<Context>/`, `KernelTestCase`, real DB —
   assert what PostGIS stored, e.g. `GeometryType(geom)`).

Commands: `composer test` (default suite), `composer test:e2e` (Panther, real
Chrome), `composer check` (deptrac + twig/container lint + tests). All green
before every commit.

## Test infrastructure facts

- Suites in `phpunit.dist.xml`: `default` (excludes `tests/Panther`) and `panther`.
- **DAMA** wraps every default-suite test in a rolled-back transaction, and the
  suite runs on `dnca_test` (doctrine `dbname_suffix`) — the dev database `dnca`
  with the real Hansen/WDPA data is never touched by tests.
- **Foundry factories live IN the context**: `src/<Context>/Factory/…Factory.php`.
- Panther classes extend `tests/Panther/E2ETestCase`, MUST carry
  `#[SkipDatabaseRollback]`, and run on their own `dnca_test_panther` DB
  (dropped + remigrated per class) because fixtures must commit for the separate
  web-server process.
- Test DSN comes from `.env.test.local` (gitignored; Symfony skips `.env.local`
  in test env). The first migration `CREATE EXTENSION IF NOT EXISTS postgis`, so
  fresh test DBs just work.

## Architecture

- Bounded contexts under `src/<Context>/` with a marker class; **deptrac is the
  law** (`deptrac.yaml`): `Spatial` is the shared spatial kernel and depends only
  on itself; topic contexts (`Forest`, future `Settlement`/`Drainage`/…) may
  depend on `[themselves, Spatial]` — never on each other.
- Thin entities (getters/setters only), lean controllers (authorize → call
  service → respond), logic in `<Context>/Service/`.
- Geometry columns: `#[ORM\Column(type: 'multipolygon')]` via
  `fundistadi/postgis-bundle` (`FundiStadi\PostGISBundle`); values are GeoJSON
  strings; migrations auto-emit `geometry(MultiPolygon,4326)` + `USING gist`.
  After schema changes, `doctrine:migrations:diff` twice — the second run must
  say "No changes" (churn = a bundle bug, report it).

## Frontend

- Tailwind 4 (tailwind-bundle): styles in `assets/styles/app.css` only — never
  inline `<style>`; rebuild with `php bin/console tailwind:build` if the fundi
  watcher isn't running.
- Map = **self-hosted Leaflet** (`assets/leaflet/`, `window.L`). Do NOT
  reintroduce MapLibre (silent worker/WebGL failures under AssetMapper).
- Icons: inline Lucide SVGs, never emoji. Stimulus controllers in
  `assets/controllers/`; keep the JS year-ramp stops identical to
  `LossYearPalette` (guarded by its unit test).
