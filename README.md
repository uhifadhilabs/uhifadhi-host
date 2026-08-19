# dnca

A Symfony (PHP 8.4) modular monolith for conservation-area geospatial analysis,
organised by **bounded context** (`src/<Context>/…`, deptrac-enforced). Spatial
data lives in **PostGIS** via the in-house [`fundistadi/fundi-postgis`](https://github.com/fundistadi/fundi-postgis)
Doctrine bundle — geometry columns are typed (`geometry(MultiPolygon,4326)`) and
get GiST indexes straight from `doctrine:migrations:diff`, no hand-written DDL.

Current contexts:
- **Geo** — shared spatial kernel.
- **Forest** — deforestation footprints from Hansen Global Forest Change
  (`ForestLossYear`: per-year `MultiPolygon` loss geometry in WGS84).

## Local development

Uses [`fundi`](https://github.com/fundistadi/fundi-cli) — no Docker. It serves
the app over HTTPS and provisions a native **PostGIS** database:

```sh
fundi server:start        # https://dnca.localhost + a PostGIS DB, DATABASE_URL wired via .env.local
php bin/console doctrine:migrations:migrate
```

The database runs in fundi's PostGIS cluster for the pinned major (see
`.fundi.local.yaml`). PostGIS must be installed for that Postgres major
(`brew install postgresql@17 postgis`).

## Auth & demo accounts

The app is behind a login. Authentication and position/permission authorization live in
the **`Uhifadhi\Access`** bounded context: a `TeamRoleEnum` tier (Super Admin / Admin / Manager
/ Staff) plus, for Staff, an assigned **Position** that bundles granular permissions
(checked by `PermissionVoter`). Managing tiers hold every permission by tier; team
administration (`/team`) is Manager-and-up.

Create a real user (prompts for the password, which is hashed):

```sh
php bin/console app:user:create <email> <First> <Last> --role=super-admin|admin|manager|staff
```

**Demo accounts.** Set a `DEMO_PASSWORD` in `.env.local`, then seed the demo team
(idempotent and non-destructive — it never purges, so it is safe against the dev DB's
real Hansen/WDPA data; the password is hashed at seed time):

```sh
php bin/console app:demo:seed
```

That creates five accounts (plus the Ranger and Analyst positions). The tier accounts
sign in with `DEMO_PASSWORD`; the Super Admin — which can impersonate anyone — gets its
own `DEMO_SUPER_ADMIN_PASSWORD`:

| Role        | Email                            | Password                    | What they can do                                     |
| ----------- | -------------------------------- | --------------------------- | ---------------------------------------------------- |
| Super Admin | `superadmin@ncaa.uhifadhi.com`   | `DEMO_SUPER_ADMIN_PASSWORD` | Everything + impersonate any user (`?_switch_user=`) |
| Admin       | `admin@ncaa.uhifadhi.com`        | `DEMO_PASSWORD`             | Everything, including `/team` administration         |
| Manager     | `manager@ncaa.uhifadhi.com`      | `DEMO_PASSWORD`             | Everything, including `/team` administration         |
| Staff       | `ranger@ncaa.uhifadhi.com`       | `DEMO_PASSWORD`             | Areas + run ingestion (Ranger position)              |
| Staff       | `analyst@ncaa.uhifadhi.com`      | `DEMO_PASSWORD`             | Areas + view/add modules (Analyst position)          |

> These are **testing** logins for the PoC — `app:demo:seed` is not run on prod. Set a
> throwaway `DEMO_PASSWORD`; the committed `.env` ships only a placeholder and the real
> value lives in `.env.local` (gitignored). For real accounts (prod), use
> `app:user:create`, which prompts for and hashes a password of your choosing.

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

**This is not a `fundi-postgis` bug** — the bundle's own schema listener mutates
the schema in place and never calls `setSchema()`; its tests pass because they run
without the Symfony bridge listeners.

**When to revert:** once `doctrine/dbal 4.5` is released, change the constraint
back to `^3.6.8` (or newer) and run `composer update doctrine/orm doctrine/dbal`.
