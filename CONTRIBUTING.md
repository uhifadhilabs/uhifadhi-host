# Contributing to uhifadhi

Thanks for looking into uhifadhi! This page is the contract for changes —
what the CI enforces and what reviewers expect.

## Contents

- [Contributor License Agreement (required)](#contributor-license-agreement-required)
- [The quality gate](#the-quality-gate)
- [Tests first](#tests-first)
- [Code conventions](#code-conventions)
- [Architecture: lean host + module bundles](#architecture-lean-host--module-bundles)
- [Database & migrations](#database--migrations)

## Contributor License Agreement (required)

Every contribution requires a signed [CLA](CLA.md) — no exceptions, and no
DCO alternative. It is an honest Apache-style individual CLA: **you keep your
copyright** and may reuse your own code anywhere, forever; you grant the
project a non-exclusive, perpetual, irrevocable license broad enough to keep
it maintainable and commercially viable (uhifadhi is AGPL-3.0 open core — the
stewardship model depends on the project retaining full licensing rights),
and you warrant the code was yours to give.

Signing is electronic and takes one comment: on your first pull request the
CLA bot asks you to post its signing sentence from your GitHub account. That
signature is recorded once and re-verified automatically on **every** pull
request and every commit pushed to it — the required CLA check blocks merging
while any commit author is unsigned. Maintainers never negotiate this case by
case; an unsigned PR simply cannot merge, however good the code.

## The quality gate

Every PR must pass the full gate — the same command CI runs:

```sh
composer check     # cs:check + phpstan + lint (twig, container) + phpunit
```

Piecemeal: `composer cs:fix` (php-cs-fixer, Symfony ruleset),
`composer phpstan` (level **max** — new code lands phpstan-max-clean),
`composer test` (PHPUnit against a **real PostGIS** database, no SQLite
stand-ins). CI provisions PostGIS and runs the gate on every push and PR;
red CI means no merge, there are no skip labels.

## Tests first

uhifadhi is TDD: the test exists before the implementation. The pyramid is
Unit (pure PHP) → Integration (kernel + real PostGIS) → Functional
(HTTP through the kernel) → a thin E2E layer. A feature PR without tests
for the new behaviour will be sent back — including (especially) for
security-sensitive surfaces like voters and permission checks.

## Code conventions

- **PHP 8.4**, strict types everywhere; modern idioms
  (`new Service()->method()` — no parentheses around `new`).
- Import classes — no inline `\Fully\Qualified\Names` in code bodies.
- Styles live in `assets/styles/app.css` (Tailwind 4) — never inline.
- Icons via `symfony/ux-icons` (`lucide:*`) — never emoji.
- Maps are self-hosted Leaflet (`window.L`).
- Comments state constraints the code can't show — not narration.

## Architecture: lean host + module bundles

The host is a flat Symfony app (`src/Entity`, `src/Controller`,
`src/Service`, …) and stays **module-blind**: it owns areas, auth/teams and
the module catalogue. Every capability ships as its own Symfony bundle that
registers a tagged module provider — its entities, routes, screens,
permissions and seed commands live in the bundle, never in the host.
Naming rules: [docs/module-naming.md](docs/module-naming.md).

Adding a module must not touch generic host code — if you find yourself
editing the host's catalogue machinery for one module, the design is wrong.

## Database & migrations

PostgreSQL + PostGIS. Geometry columns are typed through
`fundistadi/postgis-bundle`; schema changes come from
`doctrine:migrations:diff` — no hand-written DDL, and a diff run twice must
produce "No changes" before a migration is committed.
