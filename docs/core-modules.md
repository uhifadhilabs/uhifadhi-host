# Core modules and the not-uhifadhi test

A **core module** is one whose absence makes the installation *not
uhifadhi*. If we call a module core, every deployment must want it —
by definition, not by persuasion.

The test, applied to any candidate: **imagine a deployment without it.
Is what remains still the product?**

| Module | Without it… | Verdict |
|---|---|---|
| `uhifadhi/map-module` | a protected-area platform that cannot draw the area | **core** |
| `uhifadhi/team-module` | no sign-in, no rangers, no permissions — not a product at all | **core** |
| `uhifadhi/widget-module` | no surfaces; nothing renders anything | **core** |
| `uhifadhi/area-module` | the product's very subject is gone | **core** |
| patrol, incident, roster, … | a marine reserve might run incidents-only; an org might not do foot patrols — the remainder is still uhifadhi | **branch** |

Two consequences, at two different levels:

- **Deployment level** — core modules ship in the project template's
  `require` list. Nobody edits them out; wanting to remove one means it
  was misclassified, and the fix is reclassification, not removal.
- **Area level** — whether a core module can be switched *off for a
  single area* is a separate, smaller question. `core()` seeds a module
  installed per area; whether that can be toggled remains an open host
  ruling.

The trunk and the canopy are not modules at all — they are the tree
itself, above the module tier. See the
[module development guide](https://github.com/uhifadhilabs/module-contracts/blob/main/docs/module-development.md)
for how a branch registers and grows.
