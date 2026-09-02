# Module & bundle naming

How uhifadhi names the things users enable and the packages that deliver them.
A capability is a **module** to users and a **bundle** to Symfony — each word
stays in its own layer, and the namespace argues with nobody.

## Contents

- [The rule per layer](#the-rule-per-layer)
- [Why this is consistent](#why-this-is-consistent)
- [Registration is name-independent](#registration-is-name-independent)

## The rule per layer

| Layer | Rule | Example |
|---|---|---|
| Composer name, uhifadhi-exclusive | `uhifadhi/<name>-module` | `uhifadhi/patrol-module` |
| Composer name, generic (any Symfony app) | `fundistadi/<name>-bundle` | `fundistadi/postgis-bundle` |
| Repository | `github.com/uhifadhilabs/<name>-module` | [uhifadhilabs/patrol-module](https://github.com/uhifadhilabs/patrol-module) |
| PHP namespace | the DOMAIN, no meta-word | `UhifadhiLabs\Patrol\Entity\Patrol` |
| Bundle class (the one Symfony plug) | `UhifadhiLabs<Name>Bundle` | `UhifadhiLabs\Patrol\UhifadhiLabsPatrolBundle` |
| App UI / catalogue / user docs | module | the "Patrols" module tile |

Anything built to be used in uhifadhi exclusively ships with the `-module`
suffix — the name says "a uhifadhi module, not a general-purpose Symfony
package". Platform machinery extracted from the host follows the same rule
(e.g. `uhifadhi/ingestion-module`) even when it contributes no catalogue
tile of its own.

The composer vendor is `uhifadhi/` and the GitHub organisation is
`uhifadhilabs/`. They are separate namespaces and do not have to agree: the
short vendor is the one people type into `composer require`, and the repository
keeps the organisation's name. (Guzzle has published `guzzlehttp/guzzle` out of
`github.com/guzzle` for years for the same reason.)

## Why this is consistent

Each word lives in exactly one layer: **"module" appears only in the package
name and the UI; "bundle" appears only in the one class whose suffix IS the
Symfony convention; the namespace names the domain and argues with nobody.**
It also reads best at use-sites — `use UhifadhiLabs\Patrol\Entity\Patrol` says
precisely what it is.

## Registration is name-independent

Package naming never affects bundle registration: Symfony Flex keys on the
package's composer `"type": "symfony-bundle"` and registers the bundle CLASS
in `config/bundles.php` — the package name itself is never parsed, so
`uhifadhi/patrol-module` registers `UhifadhiLabsPatrolBundle` exactly like
any `*-bundle` package would.
