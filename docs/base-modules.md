# Base modules and the not-uhifadhi test

A **base module** is one whose absence makes the installation *not
uhifadhi*. If we call a module base, every deployment must want it —
by definition, not by persuasion.

The test, applied to any candidate: **imagine a deployment without it.
Is what remains still the product?**

| Module | Without it… | Verdict |
|---|---|---|
| `uhifadhi/map-module` | a protected-area platform that cannot draw the area | **base** |
| `uhifadhi/team-module` | no sign-in, no rangers, no permissions — not a product at all | **base** |
| `uhifadhi/widget-module` | no surfaces; nothing renders anything | **base** |
| `uhifadhi/area-module` | the product's very subject is gone | **base** |
| patrol, incident, roster, … | a marine reserve might run incidents-only; an org might not do foot patrols — the remainder is still uhifadhi | **installable** |

Two consequences, at two different levels:

- **Deployment level** — base modules ship in the project template's
  `require` list. Nobody edits them out; wanting to remove one means it
  was misclassified, and the fix is reclassification, not removal.
- **Area level** — whether a base module can be switched *off for a
  single area* is a separate, smaller question. `base()` seeds a module
  active per area rather than parked; whether that can then be toggled
  remains an open host ruling.

## Why "base" and not "core"

This tier was called **core modules** until the platform renamed its two
infrastructure packages, and the word did not survive the review.

"Core" marks a thing as important without saying what it *is*, and it is the
word a codebase reaches for twice — once for the runtime at the centre, once
for the set of things that ship by default. We have both. Now they have
separate words:

- the **seam** (`uhifadhi/seam-module`) is the runtime every module registers
  with — one package, a tier above modules;
- **base** is a tier *of* ordinary modules: same contract, same bundle shape,
  same seams, just seeded on rather than parked.

"Base" also names the test at the top of this page, which "core" never did. A
base module is the floor the product stands on — not a ranking of importance,
and not a claim that the module is better engineered or more central than a
branch. `ModuleProviderInterface::core()` was renamed to `base()` in the same
sweep.

## Not modules at all

The seam (`uhifadhi/seam-module`) and the shell (`uhifadhi/shell-module`) are
not modules and this page's question does not apply to them. They are the
platform itself — the thing modules register with and the thing they render
into. Asking whether an installation without the seam is still uhifadhi is
asking whether an installation with no module system is still uhifadhi.

See the
[module development guide](https://github.com/uhifadhilabs/module-contracts/blob/main/docs/module-development.md)
for how a module registers and grows.
