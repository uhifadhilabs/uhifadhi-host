# uhifadhi — Business Model

_Decided 2026-08-09. This document is written to be promoted nearly verbatim to the
public repository and the uhifadhi.org site when they launch. The licensing section
doubles as the standing answer for procurement officers and NGO partners — having it
written down beats re-explaining it every time._

## Mission

An open, self-hostable **conservation observability platform** — "Grafana for
conservation" — that turns free global datasets (Hansen forest change, CHIRPS,
FIRMS, WorldClim, ESA WorldCover, …) and a park's own instruments into the
canonical scientific figures conservation decisions are made with. The thesis:
scientists know which visual aids to use but rarely build software; programmers
build software but don't know the aids. uhifadhi bridges exactly that gap, for
protected areas in eastern and southern Africa first.

## The model in one line

**Open-core, Grafana-style: the software is free and open; the operations are the
product.**

- **uhifadhi.org** — the project: AGPL-licensed core with **all analytical modules
  included and documented**. Module breadth is the moat and the grant story;
  science is never paywalled.
- **uhifadhi.com** — the business: **managed hosting** as the primary product
  (our buyers are ecology offices, not DevOps teams), plus deployment/setup fees,
  support SLAs, training and capacity-building workshops (donor-fundable line
  items), sponsored module development ("fund the Water module"), and — later — a
  thin layer of *operational* paid add-ons: SSO/SAML, SMS/WhatsApp alert
  delivery, white-labeled report packs, cross-instance federation. We sell
  operations, never science.

Rejected alternatives, for the record: fully closed source fights the sector's
donor-and-trust culture (SMART, Global Forest Watch, OpenForis, EarthRanger — the
tools that won are all open or free) and forfeits the network effect; a purely
donation-funded model is too precarious to sustain maintenance.

## Who pays

| Segment | Examples | What they buy |
|---|---|---|
| Park authorities | national park agencies, conservation area authorities | hosted instances for their whole estate; training; SLAs |
| Landscape NGOs | NGOs operating specific ecosystems | hosted or co-managed instances; sponsored modules |
| Donor projects | bilateral/multilateral conservation grants | deployment + capacity building as a grant deliverable |
| Consortiums | WMA/community-conservancy umbrellas | one shared instance covering their members |

Individual community areas rarely pay directly — their consortium or a sponsoring
NGO does. Target scale is deliberately modest: **15–30 hosted organizations plus a
few support/training contracts plus grant funding is a sustainable business**; it
does not need to be millions. **uhifadhi labs** — a public instance carrying every
park in the database — is the shop window: marketing site, live demo, and
public-good contribution in one deployment.

## Positioning

uhifadhi is the **analytical observatory**: ingestion, statistics, visualization,
alerting. It does not compete with ranger-operations tools (SMART, EarthRanger) —
it integrates them as data sources. Go-to-market runs through the
conservation-tech community (WILDLABS, the GFW ecosystem) and a first-deployment
case study.

---

## Licensing — and the answers procurement officers ask for

The core is licensed **AGPL-3.0**; the supporting `fundistadi/*` bundles
(PostGIS, GDAL, and the planned plot/stat bundles) are **MIT**. This section
explains what that means in practice, because it is the part every procurement
officer, legal reviewer, and NGO partner asks about — and the most misunderstood.

### The GPL family and copyleft

Most open-source licenses split into two philosophies. *Permissive* licenses
(MIT, Apache-2.0 — what Symfony itself uses) say: do anything, including taking
the code proprietary. *Copyleft* licenses (the GPL family) say: you may use,
modify, and redistribute freely, **but if you distribute the software, you must
distribute your modifications under the same license.** Freedom is preserved by
making it contagious. Your users always get the source of what they're actually
running.

### The SaaS loophole, and why AGPL exists

Ordinary GPLv3 triggers only on *distribution* — handing someone a binary or a
package. But a hosting company never distributes anything: they run the software
on *their* servers and let customers use it over the network. Under GPLv3 they
could take the code, improve it privately, sell it as a service, and owe nobody a
single line of source. The **Affero GPL (AGPL-3.0)** closes exactly this hole
with its Section 13: *if you modify the software and let users interact with it
over a network, you must offer those users the source code of your modified
version.* Network use counts as distribution.

### What this means for you, concretely

- **A park or NGO self-hosting unmodified uhifadhi:** nothing changes for you.
  Run it, use it internally, never publish anything. AGPL obligations only bite
  when you *modify* the software and serve it to others.
- **A consultancy that modifies uhifadhi and hosts it for parks:** it must offer
  its modified source to those parks. Improvements flow back into the commons
  instead of becoming a proprietary fork — this is the scenario AGPL exists to
  prevent, and precisely the parasite risk a small open-source steward faces.
- **The uhifadhi maintainers: almost nothing.** The point people miss — **the
  copyright holder is not bound by their own license.** A license is permission
  granted to *others*. The steward can sell hosting, keep private operational
  patches, ship proprietary add-on modules alongside the AGPL core, even grant a
  specific partner a commercial exception (dual licensing) — because the code is
  theirs.
- **Your data is never affected.** ⚠ *This is the single most common AGPL
  misunderstanding in procurement reviews, so in bold:* **AGPL covers the code,
  not what flows through it. A park's patrol records, station telemetry,
  boundaries, and analyses remain the park's property, private, under the park's
  control — the license imposes no obligation of any kind on data.**
- **Dependencies compose fine.** AGPL applications may freely use MIT/Apache
  libraries (Symfony, Leaflet). The reverse combination is the constrained one —
  which is exactly why the fundistadi bundles stay MIT: infrastructure should be
  freely adoptable everywhere (adoption → reputation → credibility), while the
  *product* carries AGPL.

### ⚠ HIGH IMPORTANCE — two governance items the whole model depends on

These are not optional paperwork. Every commercial right described above — selling
hosting, shipping proprietary add-ons, granting commercial exceptions — rests on
**being the sole copyright steward**, and the brand value rests on **owning the
name**. Both must be in place **before the repository goes public and before the
first outside contribution is merged**, because neither can realistically be
retrofitted.

#### 1. Contributor agreements (DCO/CLA) — why, and which

**The problem they solve.** Copyright in a merged pull request belongs to *its
author*, not to the project. Without an agreement, a codebase with a hundred
outside contributors is jointly owned by a hundred people — and then the
copyright-holder immunity that powers this business model **evaporates**:
dual-licensing, commercial exceptions, or any future relicensing would require
the permission of *every* contributor, including ones who have vanished. This is
not theoretical: MongoDB and Elastic could relicense only because they had CLAs;
projects without them are permanently locked to their license by their own
history. Today the codebase is 100% one author's — the moment that stops being
true is the moment this must already be in force.

**The two instruments:**

- **DCO (Developer Certificate of Origin)** — the lightweight one. The
  contributor adds a `Signed-off-by:` line (`git commit -s`) certifying they
  have the right to submit the code under the project's license. Used by the
  Linux kernel and GitLab. Near-zero friction — but it only certifies
  *provenance*. **Contributors keep their copyright; a DCO alone does NOT
  preserve the ability to dual-license.**
- **CLA (Contributor License Agreement)** — the strong one. The contributor
  signs (once, automated by a bot such as cla-assistant on the first PR) an
  agreement granting the project a broad license to their contribution
  **including the right to relicense**. This is what keeps the steward's
  commercial rights intact as the community grows. Slightly more friction; some
  contributors dislike CLAs, which is why the *scope* should be honest — an
  Apache-style individual CLA, not a copyright grab.

**uhifadhi's rule: CLA only — no DCO fallback, no exceptions.** Every
contribution requires the signed CLA, automated from the very first outside PR.
A contributor unwilling to sign it is, by definition, unwilling to let the
project remain commercially viable — that contribution has no value to uhifadhi,
however good the code. The DCO is described above only to explain why it is
insufficient, not as an accepted alternative. Documented in `CONTRIBUTING.md`
when the repo goes public, enforced by bot so it costs maintainer time zero and
is never negotiated case by case.

**"What if a contributor later says they don't want their code used anymore?"**
They cannot withdraw it — this is exactly what the CLA is for. A standard
Apache-style CLA grants the project a **perpetual, worldwide, irrevocable**
license to the contribution. "Irrevocable" is the operative word: once signed
and merged, the grant survives the contributor's change of heart, change of
employer, or change of politics. The contributor loses nothing they need — the
grant is *non-exclusive*, so they keep their own copyright and may reuse their
code anywhere else, forever; they simply cannot un-license what they gave.
(Even without a CLA, an AGPL contribution is already irrevocable under the
license's own terms — GPLv3 §2 — so a revocation demand fails either way; the
CLA's real additions are the relicensing right and the contributor's *warranty*
that the code was theirs to give. That warranty is the recourse in the one
genuine edge case: if someone contributes code they didn't own, the CLA puts
that liability on them, and the offending code is removed regardless.)

#### 2. The "uhifadhi" trademark — the fork-proof asset

Open code does not mean an open *name*. Register the **"uhifadhi"** word mark
(start with the home jurisdiction and key markets; extend as the business does)
and publish a short trademark policy: forks are welcome and lawful — **under a
different name**. This is the Grafana/WordPress playbook: anyone may run the
code, but only the steward may sell "uhifadhi" hosting, which is exactly what
keeps managed hosting defensible while the software is free. Anyone can fork the
code; nobody can fork the brand.

Precedents on AGPL for the same reasons: **Grafana, Mastodon, Nextcloud, MinIO.**

---

## Architecture note (why hosted == self-hosted)

The deployment model is **instance-per-organization**: one app, one database, one
organization — "which parks you see" is decided by which boundaries are in your
instance's database, never by tenant filters in code. Consequences buyers care
about: your instance is **physically isolated** (no cross-tenant leak class of
bugs exists), and the hosted product is **byte-identical** to the self-hosted
one — there is no lock-in cliff between the two; leaving managed hosting means
taking your database and running the same software yourself. Details:
`HANDOVER.md` §8.3 (to be promoted to `docs/ARCHITECTURE.md`).
