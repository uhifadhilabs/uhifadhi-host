# uhifadhi

The open-source observatory for nature conservation & protected areas.

A lean Symfony (PHP 8.4) host with a flat layout (`src/Entity`, `src/Controller`,
`src/Service`, …) that is extended by **module bundles**: each capability — patrols,
incidents, rosters — ships as its own Symfony bundle
(`uhifadhilabs/<name>-module`, see [docs/module-naming.md](docs/module-naming.md))
and plugs into the host's module catalogue. The host stays module-blind: it renders
the area shell and the catalogue; modules bring their own entities, routes, screens,
permissions and seed commands.

## The tree

Uhifadhi is structured like the thing it protects:

> **`uhifadhi/seed`** (planted once) → **`trunk-module`** (the seam runtime every
> module registers with) → **branches** (the modules: patrol, incident, roster,
> map, team, widget, area…) → **`canopy-module`** (the visible crown).

The seed is the project template — copied once, so boring it never changes.
Everything above it is a bundle, updated forever through composer: modules branch
from the trunk, and the canopy is the interface the whole organism shows the sky.
A custom module registers with the trunk and shows in the canopy — the
[module-contracts](https://github.com/uhifadhilabs/module-contracts) package is
the DNA every branch carries without carrying the whole trunk.

Spatial data lives in **PostGIS** via
[`fundistadi/postgis-bundle`](https://github.com/fundistadi/postgis-bundle) —
geometry columns are typed (`geometry(MultiPolygon,4326)`, `point`, `linestring`)
and get GiST indexes straight from `doctrine:migrations:diff`, no hand-written DDL.

Deployment: a standard Symfony app shipping a production `Dockerfile`
(FrankenPHP) — build the image and run it wherever you host containers, next to
any PostGIS database.

## Contents

- [License](#license)
- [Notes](#notes)
  - [`doctrine/orm` is pinned to `3.6.7` (temporary)](#doctrineorm-is-pinned-to-367-temporary)

## License

**AGPL-3.0** — see [LICENSE](LICENSE). Use, modify and self-host freely; if you
offer a modified uhifadhi to users over a network, they are entitled to the
source of what they're running. Science is never paywalled.

## Notes

### `doctrine/orm` is pinned to `3.6.7` (temporary)

`composer.json` locks `doctrine/orm` to **`3.6.7`** on purpose. Do **not** bump it
to `^3.6.8` yet.

**Why:** orm `3.6.8` added `GenerateSchemaEventArgs::setSchema()`, which only
works with the **unreleased** `doctrine/dbal ^4.5` (it needs the `Schema::edit()`
API). Symfony's `doctrine-bridge` schema listeners — for the Messenger
`doctrine://` transport, lock, and cache — call `setSchema()` whenever the method
merely *exists*. So on `orm 3.6.8 + dbal 4.4` (the latest stable dbal),
`doctrine:migrations:diff` aborts with:

> The setSchema() method requires the DBAL Schema::edit() API … requires doctrine/dbal ^4.5 or higher.

`3.6.7` predates `setSchema()`, so the bridge falls back to its in-place
`_addTable` path and the diff works on `dbal 4.4`.

**This is not a `postgis-bundle` bug** — the bundle's own schema listener mutates
the schema in place and never calls `setSchema()`; its tests pass because they run
without the Symfony bridge listeners.

**When to revert:** once `doctrine/dbal 4.5` is released, change the constraint
back to `^3.6.8` (or newer) and run `composer update doctrine/orm doctrine/dbal`.
